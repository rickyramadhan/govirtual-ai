# GOVIRTUAL AI — AI Virtual Tour Guide Package

Self-contained AI guide package for Pano2VR virtual tours. Drop into any Pano2VR export to add an AI-powered tour guide.

## Quick Install

1. Copy the `govirtual-ai/` folder into your Pano2VR export root
2. Add the integration snippet to your `index.html` (see `install-snippet.html`)
3. Configure your API keys in `.env`
4. Add tour-specific data to `data/`
5. Verify PHP backend works by visiting `govirtual-ai/api/ai-status.php`

## Folder Structure

```
govirtual-ai/
├── frontend/           # Client-side UI
│   ├── guide.js        # Main AI guide logic
│   ├── guide.css       # Overlay styles
│   └── assets/         # Avatars, images
├── api/                # PHP backend
│   ├── chat.php        # Main chat endpoint
│   ├── ai-core.php     # Intelligence engine
│   ├── ai-provider.php # LLM provider routing
│   ├── ai-status.php   # Provider health check
│   ├── guide-policy.php # System prompt builder
│   ├── config.php      # Fallback config (gitignored)
│   └── cache/          # Embedding cache
├── data/               # Tour knowledge base
│   ├── knowledge.json
│   ├── scene-identity.json
│   ├── place-map.json
│   ├── place-registry.json
│   ├── curated-answers.json
│   ├── explore-menu-knowledge.json
│   ├── semarang-destinations.json
│   └── public-services.json
├── config/
│   └── tour.json       # Tour-specific settings
├── tools/              # Knowledge harvesters
├── tests/              # Test suite
├── docs/               # Documentation
├── .env                # API keys (gitignored)
├── .env.example        # Example environment
├── .htaccess           # Apache protection
├── install-snippet.html
└── README.md
```

## Integration Snippet

Add before `</body>` in your Pano2VR `index.html`:

```html
<!-- GOVIRTUAL AI START -->
<link rel="stylesheet" href="./govirtual-ai/frontend/guide.css">
<script>
window.GOVIRTUAL_AI_CONFIG = {
  basePath: "./govirtual-ai/",
  tourId: "your-tour-id",
  tourName: "Your Tour Name"
};
</script>
<script src="./govirtual-ai/frontend/guide.js"></script>
<!-- GOVIRTUAL AI END -->
```

## Configuration

### .env

```env
AI_PROVIDER=gemini
GEMINI_API_KEY=your_key_here
GEMINI_MODEL=gemini-3.5-flash-lite
GEMINI_FALLBACK_MODELS=gemini-2.5-flash-lite
OPENROUTER_API_KEY=your_key_here
```

### config/tour.json

```json
{
  "tourId": "your-tour-id",
  "tourName": "Your Tour Name",
  "language": "id",
  "features": {
    "exploreKnowledge": true,
    "publicService": true,
    "destinationDiscovery": true
  }
}
```

## Architecture

- **Frontend**: `guide.js` discovers `window.pano` and `window.skin` without modifying Pano2VR source
- **Backend**: PHP API with deterministic pipeline (intent classification → knowledge retrieval → LLM fallback)
- **Data**: JSON knowledge files loaded at runtime via `__DIR__`-relative paths
- **Providers**: Gemini (primary) → OpenRouter → Ollama fallback chain

## Pano2VR Bridge

The AI package integrates externally:
- Reads `window.pano.getCurrentNode()` for scene detection
- Calls `window.pano.openNext()` for navigation
- Uses `window.pano.moveTo()` for hotspot focus
- Does NOT modify `skin.js` or any Pano2VR source

## Adding to Another Tour

1. Copy `govirtual-ai/` to the new export root
2. Update `config/tour.json` with new tour identity
3. Generate new data files (`data/`) for the destination
4. Add the integration snippet to `index.html`
5. Test with a clean Pano2VR export (no Lawang Sewu data)

## License

Internal use — GoVirtual.id
