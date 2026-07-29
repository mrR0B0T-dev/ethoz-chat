// Titik masuk untuk MENJALANKAN & MENGUJI layar Asisten Ethoz.
//
// Cara cepat mencoba:
//   1) Buat project     : flutter create ethoz_test
//   2) Salin ke lib/     : ethoz_chatbot.dart  DAN  main.dart (file ini)
//   3) Tambah paket      : flutter pub add http
//   4) Jalankan          : flutter run     (pilih emulator / HP / -d chrome)
//
// File ini memakai MockChatbotService agar UI bisa dicoba TANPA backend.
// Untuk menghubungkan ke backend Laravel yang asli:
//   - ubah baris "home:" menjadi   home: const EthozChatbotScreen(),
//   - lalu set baseUrl & authToken() di ethoz_chatbot.dart,
//   - dan pastikan backend Laravel berjalan (route auth:sanctum).

import 'package:flutter/material.dart';
import 'ethoz_chatbot.dart';

void main() => runApp(const EthozChatbotDemo());

class EthozChatbotDemo extends StatelessWidget {
  const EthozChatbotDemo({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'Asisten Ethoz',
      // MODE UJI (tanpa backend):
      home: EthozChatbotScreen(service: MockChatbotService()),
      // BACKEND ASLI: home: const EthozChatbotScreen(),
    );
  }
}

// Service tiruan — mengembalikan jawaban contoh tanpa memanggil server.
// Hapus/abaikan saat sudah pakai backend asli.
class MockChatbotService extends ChatbotService {
  @override
  Future<String> send(List<ChatMessage> messages) async {
    await Future.delayed(const Duration(milliseconds: 700));
    final last = messages.isNotEmpty ? messages.last.content.toLowerCase() : '';

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
