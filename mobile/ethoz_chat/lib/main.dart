// Titik masuk untuk MENJALANKAN & MENGUJI layar Ethoz Chat.
//
// Cara cepat mencoba:
//   1) Buat project     : flutter create ethoz_test
//   2) Salin ke lib/     : ethoz_chatbot.dart  DAN  main.dart (file ini)
//   3) Tambah paket      : flutter pub add http flutter_markdown_plus
//   4) Jalankan          : flutter run     (pilih emulator / HP / -d chrome)
//
// Bawaan: memakai backend Laravel yang asli.
// Untuk meninjau tampilan TANPA backend (mis. saat menelaah desain):
//
//   flutter run -d chrome --dart-define=ETHOZ_DEMO=true
//
// Mode itu memakai MockChatbotService, lengkap dengan efek jawaban mengalir.

import 'package:flutter/material.dart';
import 'ethoz_chatbot.dart';

/// Mode peraga tanpa backend, dinyalakan lewat --dart-define=ETHOZ_DEMO=true.
const bool kDemoMode = bool.fromEnvironment('ETHOZ_DEMO');

void main() => runApp(const EthozChatbotDemo());

class EthozChatbotDemo extends StatelessWidget {
  const EthozChatbotDemo({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'Ethoz Chat',
      home: EthozChatbotScreen(
        service: kDemoMode ? MockChatbotService() : null,
      ),
    );
  }
}

// Service tiruan — mengembalikan jawaban contoh tanpa memanggil server.
// Hapus/abaikan saat sudah pakai backend asli.
class MockChatbotService extends ChatbotService {
  int _nextMessageId = 1;

  /// Tiru aliran SSE kata demi kata supaya mode uji ikut memperlihatkan
  /// pengalaman mengalir, bukan hanya jawaban yang muncul sekaligus.
  @override
  Stream<ChatChunk> stream(
    List<ChatMessage> messages, {
    int? conversationId,
  }) async* {
    final id = _nextMessageId++;
    await Future.delayed(const Duration(milliseconds: 450));

    yield ChatChunk('meta', {
      'conversation_id': conversationId ?? 1,
      'message_id': id,
      'sources': const [],
    });

    final last = messages.isNotEmpty ? messages.last.content.toLowerCase() : '';
    for (final kata in _jawaban(last).split(' ')) {
      await Future.delayed(const Duration(milliseconds: 45));
      yield ChatChunk('delta', {'text': '$kata '});
    }

    yield ChatChunk('done', {'message_id': id});
  }

  @override
  Future<ChatReply> send(
    List<ChatMessage> messages, {
    int? conversationId,
  }) async {
    await Future.delayed(const Duration(milliseconds: 700));
    final last = messages.isNotEmpty ? messages.last.content.toLowerCase() : '';

    return ChatReply(
      _jawaban(last),
      conversationId: conversationId ?? 1,
      messageId: _nextMessageId++,
    );
  }

  @override
  Future<List<ConversationSummary>> conversations() async => const [];

  /// Mode uji tidak memanggil server, jadi penilaian cukup diterima saja.
  @override
  Future<bool> rate(int messageId, String value) async => true;

  String _jawaban(String last) {
    if (last.contains('cuti')) {
      return 'Cuti tahunan Anda 12 hari kerja per tahun, berlaku setelah masa kerja '
          '1 tahun. Ajukan lewat menu Cuti minimal H-3.';
    }
    if (last.contains('slip') || last.contains('gaji')) {
      return 'E-slip terbit setiap tanggal 25 dan bisa diunduh di menu e-Slip.';
    }
    if (last.contains('izin') || last.contains('sakit')) {
      return 'Ajukan izin lewat menu Izin. Untuk sakit lebih dari 1 hari, '
          'lampirkan surat keterangan dokter.';
    }
    if (last.contains('struktur') || last.contains('organisasi')) {
      return 'Direktur Utama membawahi Direktur Operasional, Direktur Keuangan, '
          'dan Direktur SDM & Umum.';
    }
    return 'Ini jawaban contoh (mode uji tanpa backend). Hubungkan ke backend '
        'Laravel untuk jawaban sungguhan dari AI.';
  }
}
