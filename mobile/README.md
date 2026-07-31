# Mobile (Flutter) — Ethoz Chat

Layar chat Ethoz Chat untuk aplikasi mobile Ethoz.

- `ethoz_chatbot.dart` — layar `EthozChatbotScreen` + service pemanggil backend.
- `main.dart` — entry point untuk menjalankan/menguji (memakai service tiruan agar UI bisa dicoba tanpa backend).

## Menjalankan (uji)

1. `flutter create ethoz_test && cd ethoz_test`
2. Salin `ethoz_chatbot.dart` dan `main.dart` ke `lib/`
3. `flutter pub add http`
4. `flutter run`  (atau `flutter run -d chrome`)

## Menyambung ke backend asli

Di `main.dart`, ubah `home:` menjadi `const EthozChatbotScreen()`, lalu isi `baseUrl` dan `authToken()` di `ethoz_chatbot.dart`, dan pastikan route backend berada di bawah `auth:sanctum` (`/api/chatbot/send`).

## Memasang di aplikasi Ethoz

Salin `ethoz_chatbot.dart` ke `lib/`, pastikan paket `http` ada, lalu arahkan navigasi ke `EthozChatbotScreen()` dari menu.
