// Smoke test layar Ethoz Chat.
//
// Memakai service tiruan agar tidak memanggil backend sungguhan.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:ethoz_chat/ethoz_chatbot.dart';

/// Service tiruan: mengalirkan jawaban tanpa jaringan.
class _FakeChatbotService extends ChatbotService {
  /// Percakapan yang diterima tiap panggilan — untuk memastikan giliran
  /// berikutnya menyambung, bukan memulai percakapan baru.
  final List<int?> seenConversationIds = [];
  final List<({int id, String value})> ratings = [];
  final List<List<ChatMessage>> sentHistories = [];
  int _nextMessageId = 100;

  /// Potongan yang dikirim; bisa diganti per test.
  List<String> pieces = ['Cuti tahunan ', '**12 hari** kerja per tahun.'];

  @override
  Stream<ChatChunk> stream(
    List<ChatMessage> messages, {
    int? conversationId,
  }) async* {
    seenConversationIds.add(conversationId);
    sentHistories.add(List.of(messages));

    final id = _nextMessageId++;
    yield ChatChunk('meta', {'conversation_id': 7, 'message_id': id});

    for (final p in pieces) {
      yield ChatChunk('delta', {'text': p});
    }

    yield ChatChunk('done', {'message_id': id});
  }

  @override
  Future<bool> rate(int messageId, String value) async {
    ratings.add((id: messageId, value: value));
    return true;
  }

  @override
  Future<List<ConversationSummary>> conversations() async =>
      const [ConversationSummary(3, 'Pertanyaan lama soal cuti', 4)];

  @override
  Future<List<ChatMessage>> conversation(int id) async => const [
        ChatMessage('user', 'Pertanyaan lama soal cuti'),
        ChatMessage('assistant', 'Jawaban lama.', id: 55),
      ];
}

/// Aliran yang gagal dibuka; layar harus jatuh ke jalur tanpa aliran.
class _StreamFailsService extends _FakeChatbotService {
  bool sendCalled = false;

  @override
  Stream<ChatChunk> stream(
    List<ChatMessage> messages, {
    int? conversationId,
  }) async* {
    throw const ChatbotException('tidak dipakai', 'SSE diblokir');
  }

  @override
  Future<ChatReply> send(
    List<ChatMessage> messages, {
    int? conversationId,
  }) async {
    sendCalled = true;
    return const ChatReply('Jawaban lewat jalur cadangan.',
        conversationId: 9, messageId: 42);
  }
}

/// Baik aliran maupun jalur cadangan gagal.
class _AllFailService extends _StreamFailsService {
  @override
  Future<ChatReply> send(
    List<ChatMessage> messages, {
    int? conversationId,
  }) async {
    throw const ChatbotException(
        'Sesi Anda telah berakhir. Silakan masuk kembali.', 'HTTP 401');
  }
}

void main() {
  Widget wrap(ChatbotService service) =>
      MaterialApp(home: EthozChatbotScreen(service: service));

  Future<void> ask(WidgetTester tester, String text) async {
    await tester.enterText(find.byType(TextField), text);
    await tester.testTextInput.receiveAction(TextInputAction.send);
    await tester.pumpAndSettle();
  }

  testWidgets('layar pembuka menampilkan sapaan dan saran', (tester) async {
    await tester.pumpWidget(wrap(_FakeChatbotService()));

    expect(find.text('Ethoz Chat'), findsOneWidget);
    expect(find.text('Halo, saya Ethoz Chat'), findsOneWidget);
    expect(find.text('Berapa hak cuti tahunan?'), findsOneWidget);
  });

  testWidgets('jawaban yang mengalir tampil sebagai markdown', (tester) async {
    final service = _FakeChatbotService();
    await tester.pumpWidget(wrap(service));

    await ask(tester, 'Berapa hak cuti?');

    expect(find.text('Berapa hak cuti?'), findsOneWidget);
    // MarkdownBody dengan selectable:true merender lewat EditableText, bukan
    // Text biasa — findRichText membuat pencarian menembus keduanya.
    expect(
      find.textContaining('12 hari kerja per tahun.', findRichText: true),
      findsWidgets,
    );
  });

  testWidgets('giliran berikutnya menyambung percakapan yang sama', (tester) async {
    final service = _FakeChatbotService();
    await tester.pumpWidget(wrap(service));

    await ask(tester, 'Pertanyaan pertama');
    await ask(tester, 'Pertanyaan kedua');

    expect(service.seenConversationIds, [null, 7]);
  });

  testWidgets('pegawai bisa menilai jawaban', (tester) async {
    final service = _FakeChatbotService();
    await tester.pumpWidget(wrap(service));

    await ask(tester, 'Berapa hak cuti?');
    await tester.tap(find.byIcon(Icons.thumb_up_outlined));
    await tester.pumpAndSettle();

    expect(service.ratings.single.value, 'up');
    expect(find.text('Terima kasih atas masukannya'), findsOneWidget);
  });

  testWidgets('jawaban bisa diminta ulang', (tester) async {
    final service = _FakeChatbotService();
    await tester.pumpWidget(wrap(service));

    await ask(tester, 'Berapa hak cuti?');
    await tester.tap(find.byIcon(Icons.refresh_rounded));
    await tester.pumpAndSettle();

    // Dua kali memanggil aliran, dan jawaban lama tidak menumpuk.
    expect(service.seenConversationIds.length, 2);
    expect(service.sentHistories.last.where((m) => m.isBot), isEmpty);
  });

  testWidgets('percakapan baru mengosongkan layar', (tester) async {
    await tester.pumpWidget(wrap(_FakeChatbotService()));

    await ask(tester, 'Berapa hak cuti?');
    expect(find.text('Halo, saya Ethoz Chat'), findsNothing);

    await tester.tap(find.byIcon(Icons.add_rounded));
    await tester.pumpAndSettle();

    expect(find.text('Halo, saya Ethoz Chat'), findsOneWidget);
  });

  testWidgets('riwayat bisa dibuka dan dimuat', (tester) async {
    await tester.pumpWidget(wrap(_FakeChatbotService()));

    await tester.tap(find.byIcon(Icons.history_rounded));
    await tester.pumpAndSettle();
    expect(find.text('Riwayat percakapan'), findsOneWidget);

    await tester.tap(find.text('Pertanyaan lama soal cuti'));
    await tester.pumpAndSettle();

    expect(find.text('Pertanyaan lama soal cuti'), findsOneWidget);
  });

  testWidgets('aliran yang diblokir jatuh ke jalur cadangan', (tester) async {
    final service = _StreamFailsService();
    await tester.pumpWidget(wrap(service));

    await ask(tester, 'Halo');

    expect(service.sendCalled, isTrue);
    expect(find.textContaining('jalur cadangan', findRichText: true), findsWidgets);
  });

  testWidgets('menampilkan pesan galat yang spesifik saat semua gagal', (tester) async {
    await tester.pumpWidget(wrap(_AllFailService()));

    await ask(tester, 'Halo');

    expect(
      find.textContaining('Sesi Anda telah berakhir', findRichText: true),
      findsWidgets,
    );
  });

  testWidgets('sapaan pembuka tidak bisa dinilai', (tester) async {
    await tester.pumpWidget(wrap(_FakeChatbotService()));

    expect(find.byIcon(Icons.thumb_up_outlined), findsNothing);
  });
}
