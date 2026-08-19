# Lawang Sewu Virtual Tour - AI Guide System

Sistem AI pemandu virtual interaktif untuk tur virtual 360° Lawang Sewu, Semarang.

## Fitur Utama

- **AI Virtual Guide** - Pemandu virtual yang menjawab pertanyaan pengunjung secara real-time
- **Multi-Provider AI** - Mendukung Gemini, OpenRouter, Groq, dan Ollama (lokal)
- **360° Virtual Tour** - Integrasi dengan Pano2VR untuk navigasi panoramik
- **Multi-Bahasa** - Dukungan Bahasa Indonesia dan English
- **Context-Aware** - AI memahami lokasi pengunjung saat ini di dalam gedung
- **Knowledge Base** - Basis pengetahuan terverifikasi tentang Lawang Sewu dan Semarang
- **Smart Intent Classification** - Klasifikasi intent pengunjung (navigasi, deskripsi, pencarian, dll.)
- **Stateful Conversation** - Percakapan yang menjaga konteks sepanjang sesi

## Arsitektur Sistem

```
lawang-sewu/
├── api/                    # Backend AI (PHP)
│   ├── ai-core.php         # Core AI engine - intent classification, retrieval, RAG
│   ├── ai-provider.php     # Multi-provider routing (Gemini, OpenRouter, Ollama)
│   ├── guide-policy.php    # Guide persona & system prompt builder
│   ├── tour-chat.php       # Main chat API endpoint
│   ├── config.php          # Hardcoded fallback config
│   ├── ai-status.php       # Provider health check
│   └── cache/              # AI cache (embeddings, etc.)
├── data/                   # Curated knowledge data
│   ├── curated-answers.json
│   ├── explore-menu-knowledge.json
│   ├── public-services.json
│   └── semarang-destinations.json
├── tools/                  # Knowledge harvesting tools
│   ├── harvest-webview-knowledge.php
│   ├── harvest-explore-menu.php
│   ├── harvest-public-services.php
│   └── harvest-semarang-catalog.php
├── tests/                  # Test suite
│   ├── test-ai-acceptance.php
│   ├── test-lawang-sewu-intelligence.php
│   ├── test-guide-persona.php
│   └── ... (16 test files)
├── endpoint.php            # API entry point (sejajar index.html)
├── ai-client.js            # Client-side Gemini API caller
├── tour-chat.js            # Frontend chat UI logic
├── tour-chat.css           # Chat UI styling
├── tour-data.js            # Tour data structure
├── knowledge.json          # Master knowledge base
├── scene-identity.json     # Scene identity data (35 panoramas)
├── place-map.json          # Place mapping data
├── place-registry.json     # Place registry
└── index.html              # Main entry point
```

## Tech Stack

- **Backend**: PHP 8.x
- **Frontend**: Vanilla JavaScript
- **AI Providers**: Google Gemini, OpenRouter (GPT-4o-mini), Ollama (lokal)
- **Virtual Tour**: Pano2VR
- **Knowledge System**: Custom RAG (Retrieval-Augmented Generation)
- **No framework dependencies** - Pure PHP + JS

## Setup

### 1. Clone Repository

```bash
git clone https://github.com/rickyramadhan/govirtual-ai.git
cd govirtual-ai
```

### 2. Konfigurasi Environment

```bash
cp .env.example .env
```

Edit `.env` dan masukkan API keys:

```env
GEMINI_API_KEY=your_api_key_here
OPENROUTER_API_KEY=your_api_key_here
```

### 3. Server Setup

Pastikan Apache/PHP sudah berjalan (XAMPP, Laragon, dll). Akses:

```
http://localhost/lawang-sewu-ai-guide/
```

### 4. Pano2VR Assets (Opsional - untuk full virtual tour)

Untuk virtual tour lengkap, copy file Pano2VR output ke root folder:

- `media/` - File panoramik
- `tiles/` - Tile gambar
- `pano2vr_player.js` - Player engine
- `skin.js` - Skin styling
- `pano.xml` - Konfigurasi tour
- `gyro.js` - Gyroscope support

## API Endpoints

### POST `/endpoint.php`

Chat API utama.

**Request Body:**
```json
{
  "message": "Apa itu Lawang Sewu?",
  "currentScene": "loby-utama",
  "tourContext": { "scenes": [...] },
  "history": [],
  "conversationState": {},
  "viewContext": { "visibleHotspots": [] }
}
```

**Response:**
```json
{
  "reply": "Lawang Sewu adalah...",
  "intent": "ENTITY_DESCRIPTION",
  "confidence": 0.95,
  "actions": [{ "type": "none" }],
  "sceneLabel": "Loby Utama"
}
```

### GET `/api/ai-status.php`

Cek status provider AI.

## AI Intent System

Sistem mengklasifikasi pertanyaan pengunjung ke dalam intent:

| Intent | Deskripsi |
|--------|-----------|
| `ENTITY_DESCRIPTION` | Deskripsi tempat/objek |
| `GENERAL_CONVERSATION` | Obrolan umum |
| `NEARBY_SEARCH` | Pencarian di sekitar |
| `NAVIGATION_REQUEST` | Minta navigasi |
| `ROUTE_INQUIRY` | Informasi rute |
| `FACILITY_INFO` | Info fasilitas |
| `SEMARANG_DESTINATION` | Rekomendasi destinasi Semarang |
| `PUBLIC_SERVICE` | Info layanan publik |
| `HISTORY_QUESTION` | Pertanyaan sejarah |

## Knowledge Base

Knowledge base dibangun dari sumber terverifikasi:

- **Webview HTML** - 14 scene dengan 4 bahasa (ID, EN, SA, ZH)
- **Explore Menu** - Menu navigasi interaktif
- **Public Services** - Layanan publik Semarang
- **Semarang Destinations** - Katalog destinasi wisata

### Update Knowledge

```bash
# Harvest knowledge dari Pano2VR webview
php tools/harvest-webview-knowledge.php

# Harvest explore menu
php tools/harvest-explore-menu.php

# Harvest public services
php tools/harvest-public-services.php

# Harvest Semarang destinations
php tools/harvest-semarang-catalog.php
```

## Testing

```bash
# Run all tests
php tests/test-ai-acceptance.php

# Test intelligence
php tests/test-lawang-sewu-intelligence.php

# Test guide persona
php tests/test-guide-persona.php

# Test 30 demo questions
php tests/test-demo-30-questions.php
```

## Dokumentasi

- [LAWANG-SEWU-GUIDE-PERSONA.md](LAWANG-SEWU-GUIDE-PERSONA.md) - Persona & arsitektur guide
- [LAWANG-SEWU-KNOWLEDGE-HARVEST.md](LAWANG-SEWU-KNOWLEDGE-HARVEST.md) - Proses harvest knowledge
- [SCENE-IDENTITY-REPORT.md](SCENE-IDENTITY-REPORT.md) - Laporan scene identity
- [SEMARANG-DESTINATION-CATALOG-REPORT.md](SEMARANG-DESTINATION-CATALOG-REPORT.md) - Katalog destinasi

## License

Private - Untuk penggunaan pribadi dan demonstrasi.
