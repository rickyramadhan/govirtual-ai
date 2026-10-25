/* ============================================================
   tour-data.js
   Structural tour data for Lawang Sewu (standalone AI guide).

   Generated from the actual Pano2VR export (pano.xml).
   Only structural facts present in the export are included:
   - scene ids (nodeX from <panorama id="nodeX">)
   - rawName (exact Pano2VR customnodeid, or a structural
     descriptor for unnamed nodes)
   - displayName (visitor-facing editorial name; NEVER contains
     a node id)
   - navigation hotspots (title / target / pan / tilt)
   - aliases derived from real hotspot labels in the export

   Naming methodology for scenes WITHOUT customnodeid:
     - when a single real hotspot label consistently points to
       the node, that label is used as the name;
     - when a label is shared by several nodes, a neutral
       "(nodeX)" suffix in rawName keeps names unique;
     - when no single label dominates, "Koridor"/"Lorong" is a
       generic structural descriptor, NOT a tourism claim.

   Content model (description, facts, highlights, facilities,
   visibleObjects, suggestions, curatedQA) is intentionally
   EMPTY. These must be curated separately — never invented.
   ============================================================ */

window.GoVirtualTourData = {
  id: 'lawang-sewu',
  name: 'Lawang Sewu',
  scenes: [
    { id: 'node39', rawName: 'Sapi', displayName: 'Area Patung', name: 'Area Patung', aliases: ['patung', 'sculpture'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node40', rawName: 'Ruang Pameran Sejarah', displayName: 'Ruang Pameran Sejarah', name: 'Ruang Pameran Sejarah', aliases: ['ruang pameran sejarah', 'ruangpameransejarah'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node41', rawName: 'Galeri Warisan', displayName: 'Galeri Warisan', name: 'Galeri Warisan', aliases: ['galeri warisan', 'galeriwarisan'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node42', rawName: 'Maket', displayName: 'Maket', name: 'Maket', aliases: ['maket'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node43', rawName: 'Balkon Koridor', displayName: 'Balkon Koridor', name: 'Balkon Koridor', aliases: ['balkon koridor', 'koridor'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node44', rawName: 'Cetak Biru', displayName: 'Cetak Biru', name: 'Cetak Biru', aliases: ['cetak biru', 'cetakbiru', 'blueprint'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node45', rawName: 'Gedung D', displayName: 'Gedung D', name: 'Gedung D', aliases: ['gedung d', 'gedung-d', 'gedungd'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node46', rawName: 'Koridor (node46)', displayName: 'Koridor', name: 'Koridor (node46)', aliases: ['koridor'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node47', rawName: 'Bekas Tembakan', displayName: 'Bekas Tembakan', name: 'Bekas Tembakan', aliases: ['bekas tembakan', 'bekastembakan'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node48', rawName: 'Struktur Atap Arsitektur', displayName: 'Struktur Atap Arsitektur', name: 'Struktur Atap Arsitektur', aliases: ['struktur atap arsitektur', 'struktur atap'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node49', rawName: 'Toko Souvenir', displayName: 'Toko Souvenir', name: 'Toko Souvenir', aliases: ['toko souvenir', 'tokosuvenir', 'souvenir'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node50', rawName: 'Main Malam', displayName: 'Main Malam', name: 'Main Malam', aliases: ['main malam', 'mainmalam'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node51', rawName: 'Main Siang', displayName: 'Main Siang', name: 'Main Siang', aliases: ['main siang', 'mainsiang'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node52', rawName: 'Default - Night', displayName: 'Default - Night', name: 'Default - Night', aliases: ['default night', 'defaultmalam'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node53', rawName: 'Default - Day', displayName: 'Default - Day', name: 'Default - Day', aliases: ['default day', 'defaultsiang'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node54', rawName: 'Default', displayName: 'Default', name: 'Default', aliases: ['default'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node55', rawName: 'Balkon', displayName: 'Balkon', name: 'Balkon', aliases: ['balkon'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node56', rawName: 'Foodcourt', displayName: 'Foodcourt', name: 'Foodcourt', aliases: ['foodcourt', 'food court'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node57', rawName: 'Pintu Masuk', displayName: 'Pintu Masuk', name: 'Pintu Masuk', aliases: ['pintu masuk', 'pintumasuk'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node58', rawName: 'Koridor (node58)', displayName: 'Koridor', name: 'Koridor (node58)', aliases: ['koridor gedung a', 'koridor'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node59', rawName: 'Ruang Pameran', displayName: 'Ruang Pameran', name: 'Ruang Pameran', aliases: ['ruang pameran', 'ruang pameran sejarah'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node60', rawName: 'Lokomotif Listrik', displayName: 'Lokomotif Listrik', name: 'Lokomotif Listrik', aliases: ['lokomotif listrik', 'lokomotiflistrik', 'kereta listrik', 'kereta-listrik'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node61', rawName: 'Hall Siang', displayName: 'Hall Siang', name: 'Hall Siang', aliases: ['hall siang', 'hallsiang', 'lawang sewu'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node62', rawName: 'Hall Malam', displayName: 'Hall Malam', name: 'Hall Malam', aliases: ['hall malam', 'hallmalam', 'lawang sewu'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node63', rawName: 'Kereta Malam', displayName: 'Kereta Malam', name: 'Kereta Malam', aliases: ['kereta malam', 'keretamalam', 'monumen kereta api'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node64', rawName: 'Kereta Siang', displayName: 'Kereta Siang', name: 'Kereta Siang', aliases: ['kereta siang', 'keretasiang', 'kereta uap', 'kereta-uap', 'tugu', 'monumen kereta api', 'lokomotif'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node65', rawName: 'Lorong (node65)', displayName: 'Lorong', name: 'Lorong (node65)', aliases: ['lorong gedung', 'lorong'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node66', rawName: 'Lorong (node66)', displayName: 'Lorong', name: 'Lorong (node66)', aliases: ['lorong gedung', 'lorong'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node67', rawName: 'Gedung E', displayName: 'Gedung E', name: 'Gedung E', aliases: ['gedung e', 'gedung-e'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node68', rawName: 'Pintu Keluar Utama', displayName: 'Pintu Keluar Utama', name: 'Pintu Keluar Utama', aliases: ['pintu keluar utama', 'pintu keluar'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node69', rawName: 'Koridor UMKM', displayName: 'Koridor UMKM', name: 'Koridor UMKM', aliases: ['koridor umkm', 'umkm'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node70', rawName: 'Koridor Galeri', displayName: 'Koridor Galeri', name: 'Koridor Galeri', aliases: ['koridor galeri'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node71', rawName: 'Galeri Museum Kereta Api', displayName: 'Galeri Museum Kereta Api', name: 'Galeri Museum Kereta Api', aliases: ['galeri museum kereta api', 'galeri museum'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node72', rawName: 'Galeri Museum Kereta Api (node72)', displayName: 'Galeri Museum Kereta Api', name: 'Galeri Museum Kereta Api (node72)', aliases: ['galeri museum kereta api', 'galeri museum'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] },
    { id: 'node73', rawName: 'Galeri Museum Kereta Api (node73)', displayName: 'Galeri Museum Kereta Api', name: 'Galeri Museum Kereta Api (node73)', aliases: ['galeri museum kereta api', 'galeri museum'], description: '', facts: [], highlights: [], facilities: [], visibleObjects: [], suggestions: [], curatedQA: [] }
  ],
  routes: [],
  hotspots: [
    { scene: 'node39', title: 'Galeri Museum Kereta Api', target: 'node73', pan: -353.05, tilt: -10.13 },
    { scene: 'node39', title: 'Ruang Pameran Sejarah', target: 'node40', pan: -172.47, tilt: -10 },
    { scene: 'node40', title: 'Area Patung', target: 'node39', pan: -356.87, tilt: -13.21 },
    { scene: 'node40', title: 'Galeri Warisan', target: 'node41', pan: -176.77, tilt: -12.18 },
    { scene: 'node41', title: 'Ruang Pameran Sejarah', target: 'node40', pan: -11.29, tilt: -16.53 },
    { scene: 'node41', title: 'Maket', target: 'node42', pan: -190.39, tilt: -14.73 },
    { scene: 'node42', title: 'Galeri Warisan', target: 'node41', pan: -3.38, tilt: -19.2 },
    { scene: 'node43', title: 'Galeri Museum Kereta Api', target: 'node71', pan: -179.74, tilt: -20.57 },
    { scene: 'node43', title: 'Pintu Keluar Utama', target: 'node68', pan: -270.68, tilt: -13.4 },
    { scene: 'node44', title: 'Pintu Keluar', target: 'node60', pan: -7.14, tilt: -13.01 },
    { scene: 'node45', title: 'Lawang Sewu', target: 'node61', pan: 276.04, tilt: -11.81 },
    { scene: 'node45', title: 'Foodcourt', target: 'node56', pan: 92.95, tilt: -15.05 },
    { scene: 'node45', title: 'Koridor UMKM', target: 'node69', pan: 182.12, tilt: -8.1 },
    { scene: 'node46', title: 'Pintu Keluar', target: 'node58', pan: 104.05, tilt: -2.98 },
    { scene: 'node46', title: 'Struktur Atap Arsitektur', target: 'node48', pan: 86.09, tilt: -3.26 },
    { scene: 'node47', title: 'Pintu Keluar', target: 'node48', pan: 353.38, tilt: -15.7 },
    { scene: 'node48', title: 'Pintu Keluar', target: 'node46', pan: 69.98, tilt: -4.8 },
    { scene: 'node48', title: 'Struktur Atap Arsitektur', target: 'node47', pan: 253.27, tilt: -5.94 },
    { scene: 'node49', title: 'Lorong Gedung', target: 'node65', pan: -270.76, tilt: -16.28 },
    { scene: 'node49', title: 'Koridor UMKM', target: 'node69', pan: -94.54, tilt: -18.26 },
    { scene: 'node50', title: 'Lawang Sewu', target: 'node62', pan: 0.11, tilt: -21.05 },
    { scene: 'node50', title: 'Monumen Kereta Api', target: 'node63', pan: -294.22, tilt: -27.58 },
    { scene: 'node50', title: 'Balkon', target: 'node52', pan: -359.6, tilt: 20.57 },
    { scene: 'node51', title: 'Lawang Sewu', target: 'node61', pan: 359.6, tilt: -20.01 },
    { scene: 'node51', title: 'Monumen Kereta Api', target: 'node64', pan: -294.48, tilt: -26.22 },
    { scene: 'node51', title: 'Balkon', target: 'node53', pan: -0.09, tilt: 20.79 },
    { scene: 'node52', title: 'Lawang Sewu', target: 'node50', pan: 358.14, tilt: 2.03 },
    { scene: 'node53', title: 'Lawang Sewu', target: 'node51', pan: -0.99, tilt: -3.89 },
    { scene: 'node55', title: 'Pintu Masuk', target: 'node59', pan: 6.22, tilt: -12.61 },
    { scene: 'node56', title: 'Halaman Belakang', target: 'node45', pan: -88.01, tilt: -11.54 },
    { scene: 'node57', title: 'Pintu Keluar', target: 'node61', pan: -6.89, tilt: -24.16 },
    { scene: 'node57', title: 'Ruang Pameran Sejarah', target: 'node59', pan: 174.26, tilt: 6.89 },
    { scene: 'node57', title: 'Galeri Museum Kereta Api', target: 'node73', pan: -136.04, tilt: -11.42 },
    { scene: 'node58', title: 'Koridor Gedung A', target: 'node59', pan: 197.62, tilt: -7.67 },
    { scene: 'node58', title: 'Pintu Keluar', target: 'node57', pan: 247.82, tilt: -13.46 },
    { scene: 'node58', title: 'Struktur Atap Arsitektur', target: 'node46', pan: 147.4, tilt: -15.97 },
    { scene: 'node59', title: 'Pintu Keluar', target: 'node57', pan: 111.6, tilt: -12.49 },
    { scene: 'node59', title: 'Koridor Gedung A', target: 'node58', pan: 161.49, tilt: -6.72 },
    { scene: 'node59', title: 'Balkon', target: 'node55', pan: 340.04, tilt: -13.51 },
    { scene: 'node60', title: 'Lawang Sewu', target: 'node61', pan: 15.48, tilt: -1.95 },
    { scene: 'node60', title: 'Pintu Masuk', target: 'node44', pan: 177.17, tilt: -4.14 },
    { scene: 'node60', title: 'Halaman Belakang', target: 'node45', pan: -106.21, tilt: -2.53 },
    { scene: 'node61', title: 'Gedung A Ruang Pameran', target: 'node57', pan: 177.46, tilt: -6.43 },
    { scene: 'node61', title: 'Gedung C Ruang Informasi', target: 'node60', pan: -81.78, tilt: -8.54 },
    { scene: 'node61', title: 'Halaman Belakang', target: 'node45', pan: -21.76, tilt: -6.9 },
    { scene: 'node61', title: 'Monumen Kereta Api', target: 'node64', pan: 236.51, tilt: -9.51 },
    { scene: 'node61', title: 'Gedung E', target: 'node67', pan: -268.01, tilt: -5.78 },
    { scene: 'node61', title: 'Koridor UMKM', target: 'node68', pan: -341.35, tilt: -7.59 },
    { scene: 'node62', title: 'Gedung A Ruang Pameran', target: 'node57', pan: -177.7, tilt: -7.24 },
    { scene: 'node62', title: 'Monumen Kereta Api', target: 'node63', pan: -99.76, tilt: -6.17 },
    { scene: 'node63', title: 'Pintu Masuk', target: 'node62', pan: -132.65, tilt: -7.37 },
    { scene: 'node64', title: 'Lawang Sewu', target: 'node61', pan: -334.09, tilt: -1.96 },
    { scene: 'node64', title: 'Pintu Masuk', target: 'node67', pan: -136.19, tilt: -1.38 },
    { scene: 'node65', title: 'Lorong Gedung', target: 'node66', pan: 180.91, tilt: -3.86 },
    { scene: 'node65', title: 'UMKM', target: 'node49', pan: -264.63, tilt: -14.81 },
    { scene: 'node66', title: 'Koridor UMKM', target: 'node69', pan: -274.55, tilt: -4.99 },
    { scene: 'node66', title: 'Lorong Gedung', target: 'node65', pan: -3.52, tilt: -4.55 },
    { scene: 'node66', title: 'Pintu Keluar', target: 'node67', pan: -184.92, tilt: -3.15 },
    { scene: 'node67', title: 'Lawang Sewu', target: 'node61', pan: 90.03, tilt: -14.44 },
    { scene: 'node67', title: 'Lorong Gedung', target: 'node66', pan: -178.64, tilt: -4.18 },
    { scene: 'node67', title: 'Monumen Kereta Api', target: 'node64', pan: -358.53, tilt: -7.99 },
    { scene: 'node68', title: 'Koridor UMKM', target: 'node69', pan: 102.58, tilt: -8.86 },
    { scene: 'node68', title: 'Lawang Sewu', target: 'node61', pan: -357.62, tilt: -12.38 },
    { scene: 'node68', title: 'Galeri Museum Kereta Api', target: 'node72', pan: -77.46, tilt: -8.99 },
    { scene: 'node69', title: 'Koridor UMKM', target: 'node68', pan: 274.13, tilt: -9.22 },
    { scene: 'node69', title: 'Halaman Belakang', target: 'node45', pan: -264.46, tilt: -9.83 },
    { scene: 'node69', title: 'Lorong Gedung', target: 'node66', pan: 184.12, tilt: -13.34 },
    { scene: 'node70', title: 'Galeri Museum Kereta Api', target: 'node71', pan: -193.83, tilt: -13.56 },
    { scene: 'node71', title: 'Galeri Museum Kereta Api', target: 'node72', pan: -351.62, tilt: -4.78 },
    { scene: 'node71', title: 'Koridor Galeri', target: 'node70', pan: -170.83, tilt: -11.66 },
    { scene: 'node71', title: 'Balkon Koridor', target: 'node43', pan: -62.95, tilt: -14.08 },
    { scene: 'node72', title: 'Pintu Keluar', target: 'node68', pan: -60.3, tilt: -12.56 },
    { scene: 'node72', title: 'Galeri Museum Kereta Api', target: 'node71', pan: -15.51, tilt: -4.55 },
    { scene: 'node73', title: 'Galeri Museum Kereta Api', target: 'node39', pan: -181.88, tilt: -14.91 },
    { scene: 'node73', title: 'Lawang Sewu', target: 'node61', pan: -67.05, tilt: -15.34 },
    { scene: 'node73', title: 'Gedung A Ruang Pameran', target: 'node57', pan: -322.28, tilt: -18.48 }
  ],
  video360: [],
  knowledge: [],
  _sceneRegistry: {
    node39:{placeId:'sapi',placeName:'Area Patung'},node40:{placeId:'ruang-pameran-sejarah',placeName:'Ruang Pameran Sejarah'},
    node41:{placeId:'galeri-warisan',placeName:'Galeri Warisan'},node42:{placeId:'maket',placeName:'Maket'},
    node43:{placeId:'balkon-koridor',placeName:'Balkon Koridor'},node44:{placeId:'cetak-biru',placeName:'Cetak Biru Lawang Sewu'},
    node45:{placeId:'gedung-d',placeName:'Gedung D'},node46:{placeId:'koridor-node46',placeName:'Koridor'},
    node47:{placeId:'bekas-tembakan',placeName:'Bekas Tembakan'},node48:{placeId:'struktur-atap',placeName:'Struktur Atap Arsitektur'},
    node49:{placeId:'toko-souvenir',placeName:'Toko Souvenir'},node50:{placeId:'main-area',placeName:'Area Utama Lawang Sewu'},
    node51:{placeId:'main-area',placeName:'Area Utama Lawang Sewu'},node52:{placeId:'balkon',placeName:'Balkon'},
    node53:{placeId:'balkon',placeName:'Balkon'},node54:{placeId:'balkon',placeName:'Balkon'},
    node55:{placeId:'balkon',placeName:'Balkon'},node56:{placeId:'foodcourt',placeName:'Foodcourt'},
    node57:{placeId:'pintu-masuk',placeName:'Pintu Masuk'},node58:{placeId:'koridor-gedung-a',placeName:'Koridor Gedung A'},
    node59:{placeId:'ruang-pameran',placeName:'Ruang Pameran'},node60:{placeId:'lokomotif-listrik',placeName:'Lokomotif Listrik ESS'},
    node61:{placeId:'hall',placeName:'Aula Lawang Sewu'},node62:{placeId:'hall',placeName:'Aula Lawang Sewu'},
    node63:{placeId:'monumen-kereta',placeName:'Monumen Kereta Api'},node64:{placeId:'monumen-kereta',placeName:'Monumen Kereta Api'},
    node65:{placeId:'lorong',placeName:'Lorong Gedung'},node66:{placeId:'lorong',placeName:'Lorong Gedung'},
    node67:{placeId:'gedung-e',placeName:'Gedung E'},node68:{placeId:'pintu-keluar-utama',placeName:'Pintu Keluar Utama'},
    node69:{placeId:'koridor-umkm',placeName:'Koridor UMKM'},node70:{placeId:'koridor-galeri',placeName:'Koridor Galeri'},
    node71:{placeId:'galeri-museum-kereta',placeName:'Galeri Museum Kereta Api'},
    node72:{placeId:'galeri-museum-kereta',placeName:'Galeri Museum Kereta Api'},
    node73:{placeId:'galeri-museum-kereta',placeName:'Galeri Museum Kereta Api'}
  }
};
