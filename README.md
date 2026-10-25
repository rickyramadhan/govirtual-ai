# GOVIRTUAL AI — AI Virtual Tour Guide

Self-contained AI guide package for Pano2VR virtual tours. Currently deployed for **Lawang Sewu, Semarang**.

## Quick Start

1. Copy `govirtual-ai/` into any Pano2VR export root
2. Add integration snippet to `index.html` (see `govirtual-ai/install-snippet.html`)
3. Configure API keys in `govirtual-ai/.env`
4. Add tour data to `govirtual-ai/data/`

## Project Structure

```
tour-root/                     # Pano2VR export (clean)
├── 3rdparty/                  # Pano2VR native
├── images/                    # Pano2VR native
├── media/                     # Pano2VR native
├── tiles/                     # Pano2VR native
├── videos/                    # Pano2VR native
├── background_music_off.js    # Pano2VR native
├── custom.css                 # Pano2VR native
├── gyro.js                    # Pano2VR native
├── index.html                 # Only modified file (minimal AI block)
├── pano.xml                   # Pano2VR native
├── pano2vr_player.js          # Pano2VR native
├── preview.jpg                # Pano2VR native
├── skin.js                    # Pano2VR native
├── README.md
└── govirtual-ai/              # ← ALL AI FILES HERE
    ├── frontend/              # Client-side (JS/CSS/assets)
    ├── api/                   # PHP backend
    ├── data/                  # Knowledge base
    ├── config/                # Tour config
    ├── tools/                 # Knowledge harvesters
    ├── tests/                 # Test suite
    ├── docs/                  # Documentation
    ├── .env                   # API keys (gitignored)
    ├── .htaccess              # Apache protection
    ├── install-snippet.html   # Copy-paste integration
    └── README.md
```

## Tech Stack

- **Backend**: PHP 8.x (no framework)
- **Frontend**: Vanilla JavaScript (no dependencies)
- **AI Providers**: Gemini → OpenRouter → Ollama (fallback chain)
- **Virtual Tour**: Pano2VR integration
- **Knowledge**: Custom RAG (Retrieval-Augmented Generation)

## Documentation

- [govirtual-ai/README.md](govirtual-ai/README.md) — Package installation & structure
- [govirtual-ai/install-snippet.html](govirtual-ai/install-snippet.html) — Integration snippet
- [govirtual-ai/docs/LAWANG-SEWU-GUIDE-PERSONA.md](govirtual-ai/docs/LAWANG-SEWU-GUIDE-PERSONA.md) — Guide persona
- [govirtual-ai/docs/LAWANG-SEWU-KNOWLEDGE-HARVEST.md](govirtual-ai/docs/LAWANG-SEWU-KNOWLEDGE-HARVEST.md) — Knowledge harvest process

## License

Private — GoVirtual.id
