// Smoke test layar Ethoz Chat.
//
// Memakai service tiruan agar tidak memanggil backend sungguhan.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:ethoz_chat/ethoz_chatbot.dart';

/// Service tiruan: membalas tanpa jaringan.
class _FakeChatbotService extends ChatbotService {
  @override
  Future<String> send(List<ChatMessage> messages) async =>
      'Cuti tahunan Anda 12 hari kerja per tahun.';
}

/// Service tiruan yang selalu gagal, untuk menguji penanganan galat.
class _FailingChatbotService extends ChatbotService {
  @override
  Future<String> send(List<ChatMessage> messages) async {
    throw const ChatbotException('Sesi Anda telah berakhir. Silakan masuk kembali.', 'HTTP 401');
  }
}

void main() {
  Widget wrap(ChatbotService service) =>
      MaterialApp(home: EthozChatbotScreen(service: service));

  testWidgets('menampilkan sapaan awal dan chip saran', (tester) async {
    await tester.pumpWidget(wrap(_FakeChatbotService()));

    expect(find.text('Ethoz Chat'), findsOneWidget);
    expect(find.text('Halo, saya Ethoz Chat. Ada yang bisa saya bantu?'), findsOneWidget);
    expect(find.text('Berapa hak cuti tahunan?'), findsOneWidget);
  });

  testWidgets('mengirim pertanyaan lalu menampilkan balasan', (tester) async {
    await tester.pumpWidget(wrap(_FakeChatbotService()));

    await tester.enterText(find.byType(TextField), 'Berapa hak cuti?');
    await tester.testTextInput.receiveAction(TextInputAction.send);
    await tester.pumpAndSettle();

    expect(find.text('Berapa hak cuti?'), findsOneWidget);
    expect(find.text('Cuti tahunan Anda 12 hari kerja per tahun.'), findsOneWidget);
  });

  testWidgets('menampilkan pesan galat yang spesifik saat gagal', (tester) async {
    await tester.pumpWidget(wrap(_FailingChatbotService()));

    await tester.enterText(find.byType(TextField), 'Halo');
    await tester.testTextInput.receiveAction(TextInputAction.send);
    await tester.pumpAndSettle();

    // Bukan lagi pesan generik "koneksi bermasalah".
    expect(find.text('Sesi Anda telah berakhir. Silakan masuk kembali.'), findsOneWidget);
  });
}
