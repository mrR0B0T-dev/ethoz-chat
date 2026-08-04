// Ethoz Chat — layar chat untuk aplikasi mobile Ethoz (Flutter).
//
// Prasyarat pubspec.yaml:
//   dependencies:
//     http: ^1.6.0
//     flutter_markdown_plus: ^1.0.12
//
// Cara pakai: tampilkan `const EthozChatbotScreen()` sebagai satu halaman
// (mis. menu "Asisten" di Ethoz). Chat memanggil backend Laravel dan memakai
// token login pegawai yang SUDAH ADA — tidak ada login AI terpisah.

import 'dart:async';
import 'dart:convert';
// Catatan: JANGAN mengimpor dart:io di sini — aplikasi ini juga dibangun untuk
// Web, dan dart:io tidak tersedia di sana. Deteksi platform memakai
// defaultTargetPlatform dari foundation, yang aman di semua target.
import 'package:flutter/foundation.dart'
    show debugPrint, defaultTargetPlatform, kIsWeb, TargetPlatform;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart' show Clipboard, ClipboardData;
import 'package:flutter_markdown_plus/flutter_markdown_plus.dart';
import 'package:http/http.dart' as http;

// ── Konfigurasi ──────────────────────────────────────────────────
class EthozChatConfig {
  // Base URL backend Laravel.
  //
  // Bisa ditimpa saat menjalankan aplikasi, mis:
  //   flutter run --dart-define=ETHOZ_BASE_URL=http://192.168.1.10:8000
  //
  // Tanpa override, host dipilih otomatis: emulator Android tidak bisa
  // menjangkau 127.0.0.1 milik komputer host — alamatnya harus 10.0.2.2.
  static const String _override = String.fromEnvironment('ETHOZ_BASE_URL');

  static String get baseUrl {
    if (_override.isNotEmpty) return _override;
    if (!kIsWeb && defaultTargetPlatform == TargetPlatform.android) {
      return 'http://10.0.2.2:8000';
    }
    return 'http://127.0.0.1:8000';
  }

  static String get api => '$baseUrl/api';
  static String get endpoint => '$api/chatbot/send';
  static String get streamEndpoint => '$api/chatbot/stream';

  // Kembalikan token Sanctum milik pegawai yang sedang login di Ethoz.
  //
  // Utamakan --dart-define=ETHOZ_TOKEN=... agar token tidak ikut ter-commit:
  //   flutter run -d chrome --dart-define=ETHOZ_TOKEN=3|xxxxx
  static const String _tokenFromEnv = String.fromEnvironment('ETHOZ_TOKEN');

  // PERINGATAN: token uji di bawah ini ada di dalam kode sumber (ikut ter-commit
  // ke git). Ini hanya untuk pengembangan lokal — cabut token ini lewat
  // `php artisan tinker` dan hapus barisnya sebelum rilis.
  static const String _devFallbackToken =
      '3|K2rsam8Tuh3l7O6HzpzuuB28EHjIYTOdmd227jxG4c8da749';

  static Future<String?> authToken() async {
    // TODO: sambungkan ke penyimpanan token login Ethoz Anda.
    final token = _tokenFromEnv.isNotEmpty ? _tokenFromEnv : _devFallbackToken;
    return token.isEmpty ? null : token;
  }
}

/// Kegagalan saat memanggil backend, lengkap dengan pesan yang layak
/// ditampilkan ke pengguna.
class ChatbotException implements Exception {
  final String userMessage;
  final String detail;
  const ChatbotException(this.userMessage, this.detail);

  @override
  String toString() => 'ChatbotException($detail)';
}

// ── Model ────────────────────────────────────────────────────────
class ChatMessage {
  final String role; // 'user' | 'assistant'
  final String content;

  /// Id pesan di server — dipakai untuk mengirim penilaian. Null untuk sapaan
  /// pembuka dan pesan yang belum tersimpan.
  final int? id;

  const ChatMessage(this.role, this.content, {this.id});

  bool get isBot => role == 'assistant';

  ChatMessage copyWith({String? content, int? id}) =>
      ChatMessage(role, content ?? this.content, id: id ?? this.id);

  Map<String, String> toJson() => {'role': role, 'content': content};
}

/// Jawaban utuh (jalur tanpa aliran).
class ChatReply {
  final String text;
  final int? conversationId;
  final int? messageId;
  const ChatReply(this.text, {this.conversationId, this.messageId});
}

/// Satu peristiwa dari aliran SSE: meta | delta | error | done.
class ChatChunk {
  final String type;
  final Map<String, dynamic> data;
  const ChatChunk(this.type, this.data);

  String get text => (data['text'] as String?) ?? '';
}

/// Ringkasan percakapan untuk daftar riwayat.
class ConversationSummary {
  final int id;
  final String title;
  final int messageCount;
  const ConversationSummary(this.id, this.title, this.messageCount);
}

// ── Service: panggil backend Laravel ─────────────────────────────
class ChatbotService {
  Future<Map<String, String>> _headers() async {
    final token = await EthozChatConfig.authToken();
    return {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      if (token != null) 'Authorization': 'Bearer $token',
    };
  }

  /// Jawaban yang mengalir. Generator ditutup otomatis saat langganan
  /// dibatalkan (tombol "Hentikan"), sehingga koneksi ikut ditutup.
  Stream<ChatChunk> stream(
    List<ChatMessage> messages, {
    int? conversationId,
  }) async* {
    final client = http.Client();

    try {
      final request = http.Request('POST', Uri.parse(EthozChatConfig.streamEndpoint))
        ..headers.addAll(await _headers())
        ..body = jsonEncode({
          'messages': messages.map((m) => m.toJson()).toList(),
          'conversation_id': ?conversationId,
        });

      final http.StreamedResponse res;
      try {
        res = await client.send(request).timeout(const Duration(seconds: 30));
      } catch (e) {
        throw ChatbotException(
          'Tidak dapat terhubung ke server Ethoz. Pastikan backend berjalan.',
          'Gagal membuka aliran ${EthozChatConfig.streamEndpoint}: $e',
        );
      }

      if (res.statusCode == 401) {
        throw const ChatbotException(
          'Sesi Anda telah berakhir. Silakan masuk kembali.',
          'HTTP 401 — token Sanctum tidak valid atau belum diisi.',
        );
      }
      if (res.statusCode == 429) {
        throw const ChatbotException(
          'Terlalu banyak pertanyaan. Coba lagi sebentar.',
          'HTTP 429 — kena throttle.',
        );
      }
      if (res.statusCode != 200) {
        throw ChatbotException(
          'Asisten sedang bermasalah. Coba lagi sebentar.',
          'HTTP ${res.statusCode} saat membuka aliran.',
        );
      }

      var event = '';
      await for (final line
          in res.stream.transform(utf8.decoder).transform(const LineSplitter())) {
        if (line.startsWith('event:')) {
          event = line.substring(6).trim();
        } else if (line.startsWith('data:')) {
          final raw = line.substring(5).trim();
          if (raw.isEmpty) continue;
          try {
            yield ChatChunk(event, jsonDecode(raw) as Map<String, dynamic>);
          } catch (e) {
            debugPrint('[EthozChat] potongan SSE tidak terbaca: $e');
          }
        }
      }
    } finally {
      client.close();
    }
  }

  /// Jalur cadangan tanpa aliran — dipakai bila SSE gagal dibuka (mis.
  /// diblokir proxy perusahaan) sebelum satu pun teks diterima.
  Future<ChatReply> send(
    List<ChatMessage> messages, {
    int? conversationId,
  }) async {
    final http.Response res;

    try {
      res = await http
          .post(
            Uri.parse(EthozChatConfig.endpoint),
            headers: await _headers(),
            body: jsonEncode({
              'messages': messages.map((m) => m.toJson()).toList(),
              'conversation_id': ?conversationId,
            }),
          )
          .timeout(const Duration(seconds: 60));
    } catch (e) {
      throw ChatbotException(
        'Tidak dapat terhubung ke server Ethoz. Pastikan backend berjalan.',
        'Gagal memanggil ${EthozChatConfig.endpoint}: $e',
      );
    }

    if (res.statusCode == 401) {
      throw const ChatbotException(
        'Sesi Anda telah berakhir. Silakan masuk kembali.',
        'HTTP 401 — token Sanctum tidak valid atau belum diisi.',
      );
    }
    if (res.statusCode != 200) {
      throw ChatbotException(
        'Asisten sedang bermasalah. Coba lagi sebentar.',
        'HTTP ${res.statusCode}.',
      );
    }

    final data = jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
    final reply = (data['reply'] as String?)?.trim();

    return ChatReply(
      (reply != null && reply.isNotEmpty)
          ? reply
          : 'Maaf, saya belum bisa memproses itu.',
      conversationId: data['conversation_id'] as int?,
      messageId: data['message_id'] as int?,
    );
  }

  /// Kirim penilaian pegawai atas satu jawaban. Kegagalan sengaja didiamkan:
  /// penilaian bersifat pelengkap dan tidak boleh mengganggu alur chat.
  Future<bool> rate(int messageId, String value) async {
    try {
      final res = await http
          .post(
            Uri.parse('${EthozChatConfig.api}/chatbot/messages/$messageId/feedback'),
            headers: await _headers(),
            body: jsonEncode({'feedback': value}),
          )
          .timeout(const Duration(seconds: 15));

      return res.statusCode == 200;
    } catch (e) {
      debugPrint('[EthozChat] gagal mengirim penilaian: $e');
      return false;
    }
  }

  /// Daftar percakapan milik pegawai sendiri.
  Future<List<ConversationSummary>> conversations() async {
    try {
      final res = await http
          .get(Uri.parse('${EthozChatConfig.api}/chatbot/conversations'),
              headers: await _headers())
          .timeout(const Duration(seconds: 20));

      if (res.statusCode != 200) return const [];

      return (jsonDecode(utf8.decode(res.bodyBytes)) as List)
          .map((r) => ConversationSummary(
                r['id'] as int,
                (r['title'] as String?) ?? 'Percakapan',
                (r['message_count'] as int?) ?? 0,
              ))
          .toList();
    } catch (e) {
      debugPrint('[EthozChat] gagal memuat riwayat: $e');
      return const [];
    }
  }

  /// Isi satu percakapan lama.
  Future<List<ChatMessage>> conversation(int id) async {
    try {
      final res = await http
          .get(Uri.parse('${EthozChatConfig.api}/chatbot/conversations/$id'),
              headers: await _headers())
          .timeout(const Duration(seconds: 20));

      if (res.statusCode != 200) return const [];

      final data = jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;

      return (data['messages'] as List)
          .map((m) => ChatMessage(
                m['role'] as String,
                m['content'] as String,
                id: m['id'] as int?,
              ))
          .toList();
    } catch (e) {
      debugPrint('[EthozChat] gagal membuka percakapan: $e');
      return const [];
    }
  }
}

// ── Warna Ethoz ──────────────────────────────────────────────────
/// Komposisi 60/30/10 — 60% putih & netral, 30% biru, 10% emas.
///
/// Emas hanya dipakai sebagai ISIAN dengan teks/ikon gelap di atasnya
/// (tombol kirim, penanda aktif). Emas di atas putih tidak pernah dipakai
/// untuk teks: kontrasnya hanya sekitar 1.7:1.
class Ez {
  // 60% — dasar
  static const bg = Color(0xFFFFFFFF);
  static const soft = Color(0xFFF7F9FC);
  static const line = Color(0xFFE4EAF2);
  static const ink = Color(0xFF0B1E33);
  static const muted = Color(0xFF6B7C90);

  // 30% — biru struktural
  static const navy = Color(0xFF0A2A55);
  static const blue = Color(0xFF1257A8);
  static const azure = Color(0xFF1B6FD8);
  static const accent = Color(0xFF2E8AE8);
  static const sky = Color(0xFF5FAFF0);

  // 10% — emas
  static const gold = Color(0xFFF2C230);
  static const goldLight = Color(0xFFF6D256);
  static const goldDeep = Color(0xFFE9B41C);
  static const goldInk = Color(0xFF3E2E00);
}

// ── Layar chat ───────────────────────────────────────────────────
class EthozChatbotScreen extends StatefulWidget {
  /// Biarkan null untuk memakai ChatbotService asli (memanggil backend).
  /// Untuk uji tampilan tanpa backend, berikan service tiruan.
  final ChatbotService? service;
  const EthozChatbotScreen({super.key, this.service});

  @override
  State<EthozChatbotScreen> createState() => _EthozChatbotScreenState();
}

class _EthozChatbotScreenState extends State<EthozChatbotScreen> {
  late final ChatbotService _service = widget.service ?? ChatbotService();
  final _controller = TextEditingController();
  final _scroll = ScrollController();
  final _inputFocus = FocusNode();

  /// Percakapan berjalan di server; dikirim ulang tiap giliran agar riwayat
  /// dan pemantauan tidak terpecah per pertanyaan.
  int? _conversationId;

  /// Penilaian yang sudah diberikan, per id pesan.
  final Map<int, String> _ratings = {};

  final List<ChatMessage> _messages = [];

  /// Langganan aliran yang sedang berjalan — dibatalkan oleh tombol Hentikan.
  StreamSubscription<ChatChunk>? _sub;
  bool _busy = false;
  bool _receiving = false; // sudah ada teks masuk?

  static const _chips = [
    'Berapa hak cuti tahunan?',
    'Cara ajukan izin sakit?',
    'Kapan e-slip terbit?',
    'Struktur organisasi',
  ];

  @override
  void dispose() {
    _sub?.cancel();
    _controller.dispose();
    _scroll.dispose();
    _inputFocus.dispose();
    super.dispose();
  }

  bool get _isEmpty => _messages.isEmpty;

  // ── Kirim & alirkan ────────────────────────────────────────────
  Future<void> _send([String? preset]) async {
    final text = (preset ?? _controller.text).trim();
    if (text.isEmpty || _busy) return;

    setState(() {
      _messages.add(ChatMessage('user', text));
      _controller.clear();
      _busy = true;
      _receiving = false;
    });
    _scrollToEnd();

    await _run(_history());
  }

  /// Ulangi jawaban terakhir: buang jawaban lama lalu alirkan ulang.
  Future<void> _retry() async {
    if (_busy || _messages.isEmpty) return;

    setState(() {
      if (_messages.last.isBot) _messages.removeLast();
      _busy = true;
      _receiving = false;
    });

    await _run(_history());
  }

  /// Riwayat mulai dari giliran user pertama (syarat API).
  List<ChatMessage> _history() {
    final out = <ChatMessage>[];
    var started = false;
    for (final m in _messages) {
      if (!started && !(m.role == 'user')) continue;
      started = true;
      out.add(m);
    }
    return out;
  }

  Future<void> _run(List<ChatMessage> history) async {
    final completer = Completer<void>();
    var index = -1; // posisi gelembung jawaban di dalam _messages

    void appendText(String piece) {
      if (piece.isEmpty) return;
      setState(() {
        if (index < 0) {
          _messages.add(ChatMessage('assistant', piece));
          index = _messages.length - 1;
          _receiving = true;
        } else {
          _messages[index] =
              _messages[index].copyWith(content: _messages[index].content + piece);
        }
      });
      _scrollToEnd();
    }

    _sub = _service.stream(history, conversationId: _conversationId).listen(
      (chunk) {
        switch (chunk.type) {
          case 'meta':
            _conversationId = (chunk.data['conversation_id'] as int?) ?? _conversationId;
            final id = chunk.data['message_id'] as int?;
            if (id != null && index >= 0) {
              setState(() => _messages[index] = _messages[index].copyWith(id: id));
            } else if (id != null) {
              // Simpan untuk dipasang saat gelembung dibuat.
              _pendingMessageId = id;
            }
          case 'delta':
            appendText(chunk.text);
            if (_pendingMessageId != null && index >= 0 && _messages[index].id == null) {
              setState(() =>
                  _messages[index] = _messages[index].copyWith(id: _pendingMessageId));
              _pendingMessageId = null;
            }
          case 'error':
            final reply = (chunk.data['reply'] as String?) ?? '';
            if (index < 0 && reply.isNotEmpty) appendText(reply);
          case 'done':
            final id = chunk.data['message_id'] as int?;
            if (id != null && index >= 0 && _messages[index].id == null) {
              setState(() => _messages[index] = _messages[index].copyWith(id: id));
            }
        }
      },
      onError: (Object e) async {
        debugPrint('[EthozChat] aliran gagal: $e');
        // Belum ada teks sama sekali → coba jalur biasa (SSE bisa diblokir proxy).
        if (index < 0) {
          await _fallback(history, e);
        }
        if (!completer.isCompleted) completer.complete();
      },
      onDone: () {
        if (!completer.isCompleted) completer.complete();
      },
      cancelOnError: true,
    );

    await completer.future;

    if (!mounted) return;
    setState(() {
      _busy = false;
      _receiving = false;
      _sub = null;
    });
    _scrollToEnd();
  }

  int? _pendingMessageId;

  Future<void> _fallback(List<ChatMessage> history, Object streamError) async {
    try {
      final reply = await _service.send(history, conversationId: _conversationId);
      if (!mounted) return;
      setState(() {
        _conversationId = reply.conversationId ?? _conversationId;
        _messages.add(ChatMessage('assistant', reply.text, id: reply.messageId));
      });
    } catch (e) {
      if (!mounted) return;
      final message = e is ChatbotException
          ? e.userMessage
          : (streamError is ChatbotException
              ? streamError.userMessage
              : 'Maaf, koneksi ke asisten sedang bermasalah. Coba lagi sebentar.');
      setState(() => _messages.add(ChatMessage('assistant', message)));
    }
  }

  void _stop() {
    _sub?.cancel();
    if (!mounted) return;
    setState(() {
      _busy = false;
      _receiving = false;
      _sub = null;
    });
  }

  void _newChat() {
    _sub?.cancel();
    setState(() {
      _messages.clear();
      _ratings.clear();
      _conversationId = null;
      _busy = false;
      _receiving = false;
      _sub = null;
    });
  }

  Future<void> _openHistory() async {
    final list = await _service.conversations();
    if (!mounted) return;

    await showModalBottomSheet<void>(
      context: context,
      backgroundColor: Colors.transparent,
      isScrollControlled: true,
      builder: (_) => _HistorySheet(
        items: list,
        onPick: (id) async {
          Navigator.of(context).pop();
          final messages = await _service.conversation(id);
          if (!mounted) return;
          setState(() {
            _messages
              ..clear()
              ..addAll(messages);
            _conversationId = id;
          });
          _scrollToEnd();
        },
      ),
    );
  }

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.animateTo(
          _scroll.position.maxScrollExtent,
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOutCubic,
        );
      }
    });
  }

  // ── Rangka ─────────────────────────────────────────────────────
  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Ez.bg,
      body: SafeArea(
        child: Column(
          children: [
            _header(),
            Expanded(child: _isEmpty ? _welcome() : _thread()),
            _inputBar(),
          ],
        ),
      ),
    );
  }

  Widget _header() {
    return Container(
      padding: const EdgeInsets.fromLTRB(14, 12, 10, 14),
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Ez.navy, Ez.blue, Ez.azure],
        ),
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(22)),
        boxShadow: [
          BoxShadow(color: Color(0x330A2A55), blurRadius: 18, offset: Offset(0, 6)),
        ],
      ),
      child: Row(
        children: [
          Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(13),
              gradient: const LinearGradient(colors: [Ez.sky, Ez.accent]),
              boxShadow: const [
                BoxShadow(color: Color(0x595FAFF0), blurRadius: 14, offset: Offset(0, 4)),
              ],
            ),
            child: const Icon(Icons.auto_awesome, color: Ez.navy, size: 20),
          ),
          const SizedBox(width: 11),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'Ethoz Chat',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 17,
                    fontWeight: FontWeight.w600,
                    letterSpacing: .1,
                  ),
                ),
                const SizedBox(height: 2),
                Row(
                  children: [
                    // Penanda status emas — gema dari kartu presensi Ethoz.
                    Container(
                      width: 7,
                      height: 7,
                      decoration: BoxDecoration(
                        color: Ez.gold,
                        shape: BoxShape.circle,
                        boxShadow: [
                          BoxShadow(color: Ez.gold.withValues(alpha: .8), blurRadius: 7),
                        ],
                      ),
                    ),
                    const SizedBox(width: 6),
                    Text(
                      _busy ? 'Sedang menjawab…' : 'Siap membantu',
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: .85),
                        fontSize: 12,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          _headerAction(
            icon: Icons.history_rounded,
            tooltip: 'Riwayat percakapan',
            onTap: _openHistory,
          ),
          const SizedBox(width: 4),
          _headerAction(
            icon: Icons.add_rounded,
            tooltip: 'Percakapan baru',
            onTap: _isEmpty ? null : _newChat,
          ),
        ],
      ),
    );
  }

  Widget _headerAction({
    required IconData icon,
    required String tooltip,
    VoidCallback? onTap,
  }) {
    return Tooltip(
      message: tooltip,
      child: Opacity(
        opacity: onTap == null ? .4 : 1,
        child: Material(
          color: Colors.white.withValues(alpha: .16),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(11)),
          child: InkWell(
            borderRadius: BorderRadius.circular(11),
            onTap: onTap,
            child: SizedBox(
              width: 38,
              height: 38,
              child: Icon(icon, color: Colors.white, size: 19),
            ),
          ),
        ),
      ),
    );
  }

  /// Layar pembuka: sapaan + saran pertanyaan.
  Widget _welcome() {
    return ListView(
      padding: const EdgeInsets.fromLTRB(20, 34, 20, 16),
      children: [
        Center(
          child: Container(
            width: 62,
            height: 62,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(20),
              gradient: const LinearGradient(colors: [Ez.sky, Ez.accent]),
              boxShadow: const [
                BoxShadow(color: Color(0x445FAFF0), blurRadius: 22, offset: Offset(0, 8)),
              ],
            ),
            child: const Icon(Icons.auto_awesome, color: Colors.white, size: 29),
          ),
        ),
        const SizedBox(height: 18),
        const Text(
          'Halo, saya Ethoz Chat',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 20, fontWeight: FontWeight.w600, color: Ez.navy),
        ),
        const SizedBox(height: 7),
        const Text(
          'Tanyakan apa saja seputar kepegawaian dan\ninformasi perusahaan.',
          textAlign: TextAlign.center,
          style: TextStyle(fontSize: 13.5, color: Ez.muted, height: 1.5),
        ),
        const SizedBox(height: 26),
        for (final c in _chips) _suggestion(c),
      ],
    );
  }

  Widget _suggestion(String text) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 9),
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: () => _send(text),
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 15, vertical: 14),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: Ez.line),
            ),
            child: Row(
              children: [
                Expanded(
                  child: Text(
                    text,
                    style: const TextStyle(fontSize: 13.5, color: Ez.ink),
                  ),
                ),
                const Icon(Icons.arrow_outward_rounded, size: 16, color: Ez.accent),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _thread() {
    return ListView.builder(
      controller: _scroll,
      padding: const EdgeInsets.fromLTRB(16, 18, 16, 8),
      itemCount: _messages.length + (_busy && !_receiving ? 1 : 0),
      itemBuilder: (_, i) {
        if (i >= _messages.length) return _thinking();
        return _bubble(_messages[i], isLast: i == _messages.length - 1);
      },
    );
  }

  Widget _bubble(ChatMessage m, {required bool isLast}) {
    final bot = m.isBot;
    final streaming = bot && isLast && _busy && _receiving;

    return Align(
      alignment: bot ? Alignment.centerLeft : Alignment.centerRight,
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxWidth: MediaQuery.of(context).size.width * (bot ? 0.92 : 0.80),
        ),
        child: Column(
          crossAxisAlignment:
              bot ? CrossAxisAlignment.start : CrossAxisAlignment.end,
          children: [
            Container(
              margin: EdgeInsets.only(bottom: bot ? 4 : 14),
              padding: const EdgeInsets.symmetric(horizontal: 15, vertical: 11),
              decoration: BoxDecoration(
                gradient: bot ? null : const LinearGradient(colors: [Ez.accent, Ez.blue]),
                color: bot ? Colors.white : null,
                border: bot ? Border.all(color: Ez.line) : null,
                borderRadius: BorderRadius.only(
                  topLeft: const Radius.circular(17),
                  topRight: const Radius.circular(17),
                  bottomLeft: Radius.circular(bot ? 5 : 17),
                  bottomRight: Radius.circular(bot ? 17 : 5),
                ),
                boxShadow: bot
                    ? const [
                        BoxShadow(
                          color: Color(0x0F0A2A55),
                          blurRadius: 10,
                          offset: Offset(0, 3),
                        ),
                      ]
                    : null,
              ),
              child: bot
                  ? _botText(m.content, streaming: streaming)
                  : Text(
                      m.content,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 14.5,
                        height: 1.45,
                      ),
                    ),
            ),
            if (bot && !streaming) _actions(m, isLast: isLast),
          ],
        ),
      ),
    );
  }

  /// Jawaban dirender sebagai markdown ringan; kursor berkedip saat mengalir.
  Widget _botText(String content, {required bool streaming}) {
    final body = MarkdownBody(
      data: content.isEmpty ? '…' : content,
      selectable: true,
      styleSheet: MarkdownStyleSheet(
        p: const TextStyle(color: Ez.ink, fontSize: 14.5, height: 1.5),
        strong: const TextStyle(fontWeight: FontWeight.w700, color: Ez.navy),
        listBullet: const TextStyle(color: Ez.ink, fontSize: 14.5, height: 1.5),
        code: const TextStyle(
          fontSize: 13,
          backgroundColor: Ez.soft,
          fontFamily: 'monospace',
          color: Ez.blue,
        ),
        codeblockDecoration: BoxDecoration(
          color: Ez.soft,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: Ez.line),
        ),
        blockquoteDecoration: BoxDecoration(
          color: Ez.soft,
          borderRadius: BorderRadius.circular(8),
        ),
        h1: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700, color: Ez.navy),
        h2: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: Ez.navy),
        h3: const TextStyle(fontSize: 15, fontWeight: FontWeight.w600, color: Ez.navy),
        blockSpacing: 9,
      ),
    );

    if (!streaming) return body;

    return Row(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.end,
      children: [
        Flexible(child: body),
        const SizedBox(width: 3),
        const _Caret(),
      ],
    );
  }

  /// Tindakan pada jawaban: salin, ulangi, dan penilaian.
  Widget _actions(ChatMessage m, {required bool isLast}) {
    final rated = m.id == null ? null : _ratings[m.id];

    return Padding(
      padding: const EdgeInsets.only(left: 2, bottom: 14),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          _iconAction(
            icon: Icons.copy_rounded,
            tooltip: 'Salin jawaban',
            onTap: () async {
              await Clipboard.setData(ClipboardData(text: m.content));
              if (!mounted) return;
              ScaffoldMessenger.of(context).showSnackBar(
                const SnackBar(
                  content: Text('Jawaban disalin'),
                  duration: Duration(seconds: 2),
                  behavior: SnackBarBehavior.floating,
                ),
              );
            },
          ),
          if (isLast)
            _iconAction(
              icon: Icons.refresh_rounded,
              tooltip: 'Coba jawab ulang',
              onTap: _busy ? null : _retry,
            ),
          if (m.id != null) ...[
            const SizedBox(width: 2),
            if (rated == null) ...[
              _iconAction(
                icon: Icons.thumb_up_outlined,
                tooltip: 'Jawaban ini membantu',
                onTap: () => _rate(m.id!, 'up'),
              ),
              _iconAction(
                icon: Icons.thumb_down_outlined,
                tooltip: 'Jawaban ini kurang membantu',
                onTap: () => _rate(m.id!, 'down'),
              ),
            ] else
              Padding(
                padding: const EdgeInsets.only(left: 6),
                child: Text(
                  rated == 'up' ? 'Terima kasih atas masukannya' : 'Masukan terkirim',
                  style: const TextStyle(fontSize: 11.5, color: Ez.muted),
                ),
              ),
          ],
        ],
      ),
    );
  }

  Future<void> _rate(int messageId, String value) async {
    setState(() => _ratings[messageId] = value);
    await _service.rate(messageId, value);
  }

  Widget _iconAction({
    required IconData icon,
    required String tooltip,
    VoidCallback? onTap,
  }) {
    return Tooltip(
      message: tooltip,
      child: InkWell(
        borderRadius: BorderRadius.circular(999),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(6),
          child: Icon(
            icon,
            size: 15,
            color: onTap == null ? Ez.line : Ez.muted,
          ),
        ),
      ),
    );
  }

  Widget _thinking() {
    return Align(
      alignment: Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 14),
        padding: const EdgeInsets.symmetric(horizontal: 17, vertical: 15),
        decoration: BoxDecoration(
          color: Colors.white,
          border: Border.all(color: Ez.line),
          borderRadius: const BorderRadius.only(
            topLeft: Radius.circular(17),
            topRight: Radius.circular(17),
            bottomLeft: Radius.circular(5),
            bottomRight: Radius.circular(17),
          ),
        ),
        child: const _TypingDots(),
      ),
    );
  }

  Widget _inputBar() {
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: Ez.line)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.end,
        children: [
          Expanded(
            child: TextField(
              controller: _controller,
              focusNode: _inputFocus,
              enabled: !_busy,
              textInputAction: TextInputAction.send,
              onSubmitted: (_) => _send(),
              minLines: 1,
              maxLines: 5,
              style: const TextStyle(fontSize: 14.5, color: Ez.ink),
              decoration: InputDecoration(
                hintText: _busy ? 'Menunggu jawaban…' : 'Tulis pertanyaan Anda…',
                hintStyle: const TextStyle(color: Ez.muted, fontSize: 14),
                filled: true,
                fillColor: const Color(0xFFFBFCFE),
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 15, vertical: 13),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(15),
                  borderSide: const BorderSide(color: Color(0xFFDFE6EF)),
                ),
                disabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(15),
                  borderSide: const BorderSide(color: Ez.line),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(15),
                  borderSide: const BorderSide(color: Ez.accent, width: 1.6),
                ),
              ),
            ),
          ),
          const SizedBox(width: 9),
          _busy ? _stopButton() : _sendButton(),
        ],
      ),
    );
  }

  /// Aksi utama — emas dengan ikon gelap, seperti tombol "Ke Absensi"
  /// pada kartu presensi Ethoz.
  Widget _sendButton() {
    return Tooltip(
      message: 'Kirim',
      child: GestureDetector(
        onTap: () => _send(),
        child: Container(
          width: 47,
          height: 47,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(15),
            gradient: const LinearGradient(colors: [Ez.goldLight, Ez.goldDeep]),
            boxShadow: const [
              BoxShadow(color: Color(0x59E9B41C), blurRadius: 12, offset: Offset(0, 4)),
            ],
          ),
          child: const Icon(Icons.arrow_upward_rounded, color: Ez.goldInk, size: 22),
        ),
      ),
    );
  }

  Widget _stopButton() {
    return Tooltip(
      message: 'Hentikan',
      child: GestureDetector(
        onTap: _stop,
        child: Container(
          width: 47,
          height: 47,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(15),
            color: Ez.soft,
            border: Border.all(color: Ez.line),
          ),
          child: const Icon(Icons.stop_rounded, color: Ez.navy, size: 22),
        ),
      ),
    );
  }
}

// ── Riwayat percakapan ───────────────────────────────────────────
class _HistorySheet extends StatelessWidget {
  final List<ConversationSummary> items;
  final void Function(int id) onPick;
  const _HistorySheet({required this.items, required this.onPick});

  @override
  Widget build(BuildContext context) {
    return Container(
      constraints: BoxConstraints(
        maxHeight: MediaQuery.of(context).size.height * .7,
      ),
      decoration: const BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.vertical(top: Radius.circular(22)),
      ),
      padding: const EdgeInsets.fromLTRB(18, 12, 18, 22),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Center(
            child: Container(
              width: 40,
              height: 4,
              decoration: BoxDecoration(
                color: Ez.line,
                borderRadius: BorderRadius.circular(999),
              ),
            ),
          ),
          const SizedBox(height: 16),
          const Text(
            'Riwayat percakapan',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: Ez.navy),
          ),
          const SizedBox(height: 12),
          if (items.isEmpty)
            const Padding(
              padding: EdgeInsets.symmetric(vertical: 26),
              child: Text(
                'Belum ada percakapan tersimpan.',
                style: TextStyle(color: Ez.muted, fontSize: 13),
              ),
            )
          else
            Flexible(
              child: ListView.separated(
                shrinkWrap: true,
                itemCount: items.length,
                separatorBuilder: (_, _) => const SizedBox(height: 8),
                itemBuilder: (_, i) {
                  final c = items[i];
                  return Material(
                    color: Ez.soft,
                    borderRadius: BorderRadius.circular(13),
                    child: InkWell(
                      borderRadius: BorderRadius.circular(13),
                      onTap: () => onPick(c.id),
                      child: Padding(
                        padding:
                            const EdgeInsets.symmetric(horizontal: 14, vertical: 13),
                        child: Text(
                          c.title,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontSize: 13.5, color: Ez.ink),
                        ),
                      ),
                    ),
                  );
                },
              ),
            ),
        ],
      ),
    );
  }
}

// ── Kursor berkedip saat jawaban mengalir ────────────────────────
class _Caret extends StatefulWidget {
  const _Caret();

  @override
  State<_Caret> createState() => _CaretState();
}

class _CaretState extends State<_Caret> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 900),
  )..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _c,
      builder: (_, _) {
        final on = _c.value < .5;
        return Container(
          width: 7,
          height: 15,
          margin: const EdgeInsets.only(bottom: 3),
          decoration: BoxDecoration(
            color: on ? Ez.accent : Colors.transparent,
            borderRadius: BorderRadius.circular(2),
          ),
        );
      },
    );
  }
}

// ── Indikator "sedang mengetik" ──────────────────────────────────
class _TypingDots extends StatefulWidget {
  const _TypingDots();

  @override
  State<_TypingDots> createState() => _TypingDotsState();
}

class _TypingDotsState extends State<_TypingDots>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1000),
  )..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _c,
      builder: (_, _) {
        return Row(
          mainAxisSize: MainAxisSize.min,
          children: List.generate(3, (i) {
            final t = (_c.value + i * 0.2) % 1.0;
            final o = 0.3 + 0.7 * (t < 0.5 ? t * 2 : (1 - t) * 2);
            return Container(
              margin: const EdgeInsets.symmetric(horizontal: 2),
              width: 6,
              height: 6,
              decoration: BoxDecoration(
                color: Ez.accent.withValues(alpha: o.clamp(0.0, 1.0)),
                shape: BoxShape.circle,
              ),
            );
          }),
        );
      },
    );
  }
}
