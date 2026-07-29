// Asisten Ethoz — layar chat untuk aplikasi mobile Ethoz (Flutter).
//
// Prasyarat pubspec.yaml:
//   dependencies:
//     http: ^1.2.0
//
// Cara pakai: tampilkan `const EthozChatbotScreen()` sebagai satu halaman
// (mis. menu "Asisten" di Ethoz). Chat memanggil backend Laravel dan memakai
// token login pegawai yang SUDAH ADA — tidak ada login AI terpisah.

import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;

// ── Konfigurasi ──────────────────────────────────────────────────
class EthozChatConfig {
  // Base URL backend Laravel Anda.
  static const String baseUrl = 'https://ethoz.perusahaan.co.id';

  // Route chatbot (daftarkan di routes/api.php dengan middleware auth:sanctum).
  static const String endpoint = '$baseUrl/api/chatbot/send';

  // Kembalikan token Sanctum milik pegawai yang sedang login di Ethoz.
  // Ambil dari penyimpanan sesi yang sudah Anda pakai (mis. flutter_secure_storage
  // atau provider auth Anda). TIDAK perlu token/akun AI khusus.
  static Future<String?> authToken() async {
    // TODO: sambungkan ke penyimpanan token login Ethoz Anda.
    return 'ISI_TOKEN_SANCTUM_PEGAWAI';
  }
}

// ── Model pesan ──────────────────────────────────────────────────
class ChatMessage {
  final String role; // 'user' | 'assistant'
  final String content;
  const ChatMessage(this.role, this.content);
  Map<String, String> toJson() => {'role': role, 'content': content};
}

// ── Service: panggil backend Laravel ─────────────────────────────
class ChatbotService {
  Future<String> send(List<ChatMessage> messages) async {
    final token = await EthozChatConfig.authToken();
    final res = await http
        .post(
          Uri.parse(EthozChatConfig.endpoint),
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            if (token != null) 'Authorization': 'Bearer $token',
          },
          body: jsonEncode(
            {'messages': messages.map((m) => m.toJson()).toList()},
          ),
        )
        .timeout(const Duration(seconds: 60));

    if (res.statusCode == 200) {
      final data = jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
      final reply = (data['reply'] as String?)?.trim();
      return (reply != null && reply.isNotEmpty)
          ? reply
          : 'Maaf, saya belum bisa memproses itu.';
    }
    throw Exception('HTTP ${res.statusCode}');
  }
}

// ── Warna Ethoz ──────────────────────────────────────────────────
class Ez {
  static const navy = Color(0xFF031334);
  static const blue = Color(0xFF004A7B);
  static const teal = Color(0xFF00796E);
  static const accTeal = Color(0xFF00A88F);
  static const mint = Color(0xFF00F6A5);
  static const ink = Color(0xFF0B1B2B);
  static const muted = Color(0xFF6B7B87);
  static const soft = Color(0xFFF3F8F7);
  static const line = Color(0xFFE6EFEC);
  static const bg = Color(0xFFF6FBFA);
}

// ── Layar chat ───────────────────────────────────────────────────
class EthozChatbotScreen extends StatefulWidget {
  // Biarkan null untuk memakai ChatbotService asli (memanggil backend Laravel).
  // Untuk uji tampilan tanpa backend, berikan service tiruan (lihat main.dart).
  final ChatbotService? service;
  const EthozChatbotScreen({super.key, this.service});

  @override
  State<EthozChatbotScreen> createState() => _EthozChatbotScreenState();
}

class _EthozChatbotScreenState extends State<EthozChatbotScreen> {
  late final ChatbotService _service = widget.service ?? ChatbotService();
  final _controller = TextEditingController();
  final _scroll = ScrollController();
  bool _loading = false;

  final List<ChatMessage> _messages = [
    const ChatMessage(
      'assistant',
      'Halo, saya Asisten Ethoz. Ada yang bisa saya bantu?',
    ),
  ];

  static const _chips = [
    'Berapa hak cuti tahunan?',
    'Cara ajukan izin sakit?',
    'Kapan e-slip terbit?',
    'Struktur organisasi',
  ];

  @override
  void dispose() {
    _controller.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _send([String? preset]) async {
    final text = (preset ?? _controller.text).trim();
    if (text.isEmpty || _loading) return;

    setState(() {
      _messages.add(ChatMessage('user', text));
      _controller.clear();
      _loading = true;
    });
    _scrollToEnd();

    // Kirim riwayat mulai dari giliran user pertama (syarat API).
    final history = <ChatMessage>[];
    var started = false;
    for (final m in _messages) {
      if (!started && m.role != 'user') continue;
      started = true;
      history.add(m);
    }

    try {
      final reply = await _service.send(history);
      if (!mounted) return;
      setState(() => _messages.add(ChatMessage('assistant', reply)));
    } catch (_) {
      if (!mounted) return;
      setState(() => _messages.add(const ChatMessage(
            'assistant',
            'Maaf, koneksi ke asisten sedang bermasalah. Coba lagi sebentar.',
          )));
    } finally {
      if (mounted) setState(() => _loading = false);
      _scrollToEnd();
    }
  }

  void _scrollToEnd() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_scroll.hasClients) {
        _scroll.animateTo(
          _scroll.position.maxScrollExtent,
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeOut,
        );
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Ez.bg,
      body: SafeArea(
        child: Column(
          children: [
            _header(),
            Expanded(child: _thread()),
            if (_messages.length <= 1 && !_loading) _chipRow(),
            _inputBar(),
          ],
        ),
      ),
    );
  }

  Widget _header() {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 16, 14),
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Ez.navy, Ez.blue, Ez.teal],
        ),
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(20)),
      ),
      child: Row(
        children: [
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(13),
              gradient: const LinearGradient(colors: [Ez.mint, Ez.accTeal]),
            ),
            child: const Icon(Icons.auto_awesome, color: Ez.navy, size: 22),
          ),
          const SizedBox(width: 12),
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'Asisten Ethoz',
                style: TextStyle(
                  color: Colors.white,
                  fontSize: 17,
                  fontWeight: FontWeight.w600,
                ),
              ),
              const SizedBox(height: 2),
              Row(
                children: [
                  Container(
                    width: 7,
                    height: 7,
                    decoration: const BoxDecoration(
                      color: Ez.mint,
                      shape: BoxShape.circle,
                    ),
                  ),
                  const SizedBox(width: 6),
                  Text(
                    'Online • PT BDP',
                    style: TextStyle(
                      color: Colors.white.withOpacity(0.85),
                      fontSize: 12,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _thread() {
    return ListView(
      controller: _scroll,
      padding: const EdgeInsets.all(16),
      children: [
        for (final m in _messages) _bubble(m),
        if (_loading) _typingBubble(),
      ],
    );
  }

  Widget _bubble(ChatMessage m) {
    final bot = m.role == 'assistant';
    return Align(
      alignment: bot ? Alignment.centerLeft : Alignment.centerRight,
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxWidth: MediaQuery.of(context).size.width * 0.78,
        ),
        child: Container(
          margin: const EdgeInsets.only(bottom: 12),
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(
            gradient: bot
                ? null
                : const LinearGradient(colors: [Ez.accTeal, Ez.blue]),
            color: bot ? Ez.soft : null,
            border: bot ? Border.all(color: Ez.line) : null,
            borderRadius: BorderRadius.only(
              topLeft: const Radius.circular(16),
              topRight: const Radius.circular(16),
              bottomLeft: Radius.circular(bot ? 5 : 16),
              bottomRight: Radius.circular(bot ? 16 : 5),
            ),
          ),
          child: Text(
            m.content,
            style: TextStyle(
              color: bot ? Ez.ink : Colors.white,
              fontSize: 14.5,
              height: 1.45,
            ),
          ),
        ),
      ),
    );
  }

  Widget _typingBubble() {
    return Align(
      alignment: Alignment.centerLeft,
      child: Container(
        margin: const EdgeInsets.only(bottom: 12),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: Ez.soft,
          border: Border.all(color: Ez.line),
          borderRadius: const BorderRadius.only(
            topLeft: Radius.circular(16),
            topRight: Radius.circular(16),
            bottomLeft: Radius.circular(5),
            bottomRight: Radius.circular(16),
          ),
        ),
        child: const _TypingDots(),
      ),
    );
  }

  Widget _chipRow() {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.fromLTRB(16, 0, 16, 10),
      child: Wrap(
        spacing: 8,
        runSpacing: 8,
        children: _chips.map((c) {
          return InkWell(
            borderRadius: BorderRadius.circular(999),
            onTap: () => _send(c),
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
              decoration: BoxDecoration(
                color: const Color(0xFFECF7F4),
                border: Border.all(color: const Color(0xFFCDEAE3)),
                borderRadius: BorderRadius.circular(999),
              ),
              child: Text(
                c,
                style: const TextStyle(
                  color: Ez.teal,
                  fontSize: 12.5,
                  fontWeight: FontWeight.w500,
                ),
              ),
            ),
          );
        }).toList(),
      ),
    );
  }

  Widget _inputBar() {
    final canSend = !_loading;
    return Container(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
      decoration: const BoxDecoration(
        color: Colors.white,
        border: Border(top: BorderSide(color: Ez.line)),
      ),
      child: Row(
        children: [
          Expanded(
            child: TextField(
              controller: _controller,
              textInputAction: TextInputAction.send,
              onSubmitted: (_) => _send(),
              minLines: 1,
              maxLines: 4,
              decoration: InputDecoration(
                hintText: 'Tulis pertanyaan Anda…',
                hintStyle: const TextStyle(color: Ez.muted, fontSize: 14),
                filled: true,
                fillColor: const Color(0xFFF8FBFA),
                contentPadding:
                    const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: const BorderSide(color: Color(0xFFDDE8E5)),
                ),
                focusedBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(14),
                  borderSide: const BorderSide(color: Ez.accTeal, width: 1.6),
                ),
              ),
            ),
          ),
          const SizedBox(width: 8),
          GestureDetector(
            onTap: canSend ? () => _send() : null,
            child: Opacity(
              opacity: canSend ? 1 : 0.45,
              child: Container(
                width: 46,
                height: 46,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(14),
                  gradient: const LinearGradient(colors: [Ez.mint, Ez.accTeal]),
                ),
                child: const Icon(Icons.send_rounded, color: Ez.navy, size: 22),
              ),
            ),
          ),
        ],
      ),
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
      builder: (_, __) {
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
                color: Ez.accTeal.withOpacity(o.clamp(0.0, 1.0)),
                shape: BoxShape.circle,
              ),
            );
          }),
        );
      },
    );
  }
}
