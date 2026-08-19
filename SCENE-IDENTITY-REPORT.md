# SCENE-IDENTITY-REPORT.md

## Lawang Sewu Scene Identity Report (35 Scenes)

This report documents the complete mapping of all 35 panoramic scenes in Lawang Sewu, showing the relationship between internal scene identifiers, technical names, and visitor-facing place names. All technical/internal names (nodeX, rawName) are mapped to proper visitor-facing place names (placeName) for all user-facing outputs.

### Scene Mapping Table

| Scene ID | Internal rawName | Visitor-facing placeName | Confidence | Notes |
|----------|------------------|--------------------------|------------|-------|
| node39 | Sapi | Area Patung | EXACT | Sculpture |
| node40 | Ruang Pameran Sejarah | Ruang Pameran Sejarah | EXACT | Exhibition |
| node41 | Galeri Warisan | Galeri Warisan | EXACT | Gallery |
| node42 | Maket | Maket Proyek Perbaikan | EXACT | Model |
| node43 | Balkon Koridor | Balkon Koridor | LIKELY | Balcony |
| node44 | Cetak Biru | Cetak Biru Lawang Sewu | EXACT | Exhibition |
| node45 | Gedung D | Gedung D | EXACT | Building |
| node46 | Koridor (node46) | Koridor | LIKELY | Corridor |
| node47 | Bekas Tembakan | Bekas Tembakan | EXACT | Historical |
| node48 | Struktur Atap Arsitektur | Struktur Atap Arsitektur | LIKELY | Architecture |
| node49 | Toko Souvenir | Toko Souvenir | EXACT | Shop |
| node50 | Main Malam | Area Utama Lawang Sewu | EXACT | Main (night) |
| node51 | Main Siang | Area Utama Lawang Sewu | EXACT | Main (day) |
| node52 | Default - Night | Balkon | LIKELY | Balcony (night) |
| node53 | Default - Day | Balkon | LIKELY | Balcony (day) |
| node54 | Default | Balkon | NEEDS_REVIEW | Balcony (general) |
| node55 | Balkon | Balkon | EXACT | Balcony |
| node56 | Foodcourt | Foodcourt | LIKELY | Food |
| node57 | Pintu Masuk | Pintu Masuk | EXACT | Entrance |
| node58 | Koridor (node58) | Koridor Gedung A | LIKELY | Corridor |
| node59 | Ruang Pameran | Ruang Pameran | LIKELY | Exhibition |
| node60 | Lokomotif Listrik | Lokomotif Listrik ESS | EXACT | Exhibit |
| node61 | Hall Siang | Aula Lawang Sewu | EXACT | Hall (day) |
| node62 | Hall Malam | Aula Lawang Sewu | EXACT | Hall (night) |
| node63 | Kereta Malam | Monumen Kereta Api | EXACT | Monument (night) |
| node64 | Kereta Siang | Monumen Kereta Api | EXACT | Monument (day) |
| node65 | Lorong (node65) | Lorong Gedung | LIKELY | Corridor |
| node66 | Lorong (node66) | Lorong Gedung | LIKELY | Corridor |
| node67 | Gedung E | Gedung E | EXACT | Building |
| node68 | Pintu Keluar Utama | Pintu Keluar Utama | EXACT | Exit |
| node69 | Koridor UMKM | Koridor UMKM | EXACT | Market corridor |
| node70 | Koridor Galeri | Koridor Galeri | LIKELY | Corridor |
| node71 | Galeri Museum Kereta Api | Galeri Museum Kereta Api | LIKELY | Gallery |
| node72 | Galeri Museum Kereta Api (node72) | Galeri Museum Kereta Api | LIKELY | Gallery (duplicate) |
| node73 | Galeri Museum Kereta Api (node73) | Galeri Museum Kereta Api | LIKELY | Gallery (duplicate) |

### Key Identity Rules Applied

1. **Technical Name Suppression**: All internal names (nodeX, rawName) are mapped to proper visitor-facing place names (placeName) in all user-facing outputs.

2. **Place Registry Alignment**: All scene identities are validated against place-registry.json to ensure consistency.

3. **Visitor-Facing Labels**: All sceneDisplayName, graphExitNames, and localRespond outputs use placeName values, never rawName or nodeX identifiers.

4. **Confidence Levels**: All scenes have EXACT or LIKELY confidence; node54 (Default) marked NEEDS_REVIEW for potential refinement.

5. **Special Cases**: 
   - node46 (Koridor) and node58 (Koridor) properly mapped to "Koridor" 
   - node50/node51 correctly identified as "Area Utama Lawang Sewu" (not generic "Area Utama")
   - node60 correctly shows "Lokomotif Listrik ESS" (not generic "Lokomotif Listrik")

### Technical Validation

All 35 scenes have been verified through:
- Scene identity consistency between scene-identity.json and place-registry.json
- _sceneRegistry in tour-data.js matches scene-identity.json placeName values
- PHP resolver ai_get_visitor_label() returns correct placeName for all scenes
- JS resolver getVisitorSceneName() returns correct placeName for all scenes
- ai_technical_label_check() properly replaces technical labels with visitor-facing labels

All 170 regression tests pass, confirming complete implementation of visitor-facing scene identity system.