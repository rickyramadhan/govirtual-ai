(function () {
  'use strict';

  var data = window.GoVirtualTourData;
  if (!data) return;
  var apiUrl = 'endpoint.php';
  var history = [];
  var pendingClarification = null;
  var submitQuery = null;
  var activeRequest = null;
  var lastSubmitted = { text: '', at: 0 };
  var conversationState = {
    lastIntent: null,
    lastSceneId: null,
    lastRoute: null,
    lastEntity: null,
    lastReferencedEntity: null,
    lastReferencedDestination: null,
    lastNavigableSceneId: null,
    lastRecommendedSceneId: null,
    lastRecommendedDestination: null,
    lastMentionedSceneIds: [],
    lastDiscussedEntity: null,
    lastDiscussedEntityType: null,
    lastNavigableEntity: null,
    lastPublicServiceEntity: null,
    recommendedRouteId: null,
    activeRouteId: null,
    activeRouteSceneIds: [],
    activeRouteStep: null,
    lastReply: '',
    visitorProfile: { transport: null, travelingWithChildren: null, accessibilityNeeds: [], servicePreference: null, interests: [], destinationPreference: null },
    activeRoute: null,
    routeProgress: null,
    conversationSummary: null,
    visitedSceneIds: []
  };

  /* ---- Semarang destination catalog state ---- */
  var _semarangCatalog = null;
  var _pendingDestination = null;
  var _lastDestinationResults = [];

  /* ---- Scene identity state (answerability) ---- */
  var _sceneIdentityData = null;
  var _sceneIdentityLoading = null;

  /* ---- Knowledge state (answerability) ---- */
  var _knowledgeData = null;
  var _knowledgeLoading = null;

  /* ---- Provider health state ---- */
  var _providerHealth = null;
  var _providerHealthLoading = null;

  /* ---- progressive render state ---- */
  var _streamState = null; // { timerId, fullText, chunks, index, el, caret }

  /* ================================================================
     AI INTELLIGENCE — UNTOUCHED
     ================================================================ */

  function normalize(value) {
    return value.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9\s]/g, ' ').replace(/\s+/g, ' ').trim();
  }

  /* ================================================================
     SEMARANG DESTINATION CATALOG
     ================================================================ */

  function loadSemarangCatalog() {
    if (_semarangCatalog) return Promise.resolve(_semarangCatalog);
    return fetch('data/semarang-destinations.json')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.destinations) return null;
        _semarangCatalog = data;
        return data;
      })
      .catch(function () { return null; });
  }

  var _catalogTermMap = null;

  function buildCatalogTermMap(catalog) {
    if (_catalogTermMap) return _catalogTermMap;
    _catalogTermMap = {};
    catalog.categories.forEach(function (cat) {
      var terms = [cat.name.toLowerCase()].concat((cat.aliases || []).map(function (a) { return a.toLowerCase(); }));
      terms.forEach(function (t) { if (t) _catalogTermMap[t] = cat.id; });
    });
    return _catalogTermMap;
  }

  function detectDiscoveryCategory(query) {
    if (!_semarangCatalog) return null;
    var text = normalize(query);
    var termMap = buildCatalogTermMap(_semarangCatalog);

    // Direct category term match
    var bestCat = null;
    var bestScore = 0;
    Object.keys(termMap).forEach(function (term) {
      if (text.indexOf(term) !== -1 && term.length > bestScore) {
        bestScore = term.length;
        bestCat = termMap[term];
      }
    });
    if (bestCat) return bestCat;

    // Check aliases in categories
    for (var i = 0; i < _semarangCatalog.categories.length; i++) {
      var cat = _semarangCatalog.categories[i];
      if (cat.aliases) {
        for (var j = 0; j < cat.aliases.length; j++) {
          if (text.indexOf(cat.aliases[j].toLowerCase()) !== -1) return cat.id;
        }
      }
    }
    return null;
  }

  function searchDestinations(query, categoryId) {
    if (!_semarangCatalog) return [];
    var text = normalize(query);
    var dests = _semarangCatalog.destinations;

    if (categoryId) {
      dests = dests.filter(function (d) { return d.categoryId === categoryId; });
    }

    var scored = dests.map(function (d) {
      var score = 0;
      var nameLower = d.name.toLowerCase();
      var nameNorm = normalize(d.name);

      // Exact name match
      if (text === nameNorm) { score = 100; }
      // Name contains query or query contains name
      else if (text.indexOf(nameNorm) !== -1) { score = Math.max(score, nameNorm.length * 2); }
      else if (nameNorm.indexOf(text) !== -1) { score = Math.max(score, text.length * 2); }

      // Alias match
      (d.aliases || []).forEach(function (alias) {
        var aliasNorm = normalize(alias);
        if (text.indexOf(aliasNorm) !== -1) score = Math.max(score, aliasNorm.length + 5);
        if (aliasNorm.indexOf(text) !== -1) score = Math.max(score, text.length + 5);
      });

      // Category match bonus
      if (categoryId && d.categoryId === categoryId) score += 3;

      // Virtual tour available as tie-breaker
      if (d.virtualTourAvailable) score += 0.5;

      return { destination: d, score: score };
    });

    return scored
      .filter(function (s) { return s.score > 0; })
      .sort(function (a, b) { return b.score - a.score; });
  }

  function isDestinationDiscoveryQuery(query) {
    var text = normalize(query);
    // Proximity queries should NOT trigger destination discovery — go to Gemini instead
    if (/(sekitar|terdekat|dekat|nearby|around|di sini|sini)\b/.test(text) && /\b(masjid|gereja|rumah sakit|apotek|klinik|hotel|restoran|cafe|bank|atm|spbu|parkir|terminal|stasiun)\b/.test(text)) return false;
    // Discovery patterns
    var patterns = [
      /\b(ada|apakah ada|tersedia|bisakah|bisa)\b.*\b(masjid|gereja|vihara|klenteng|museum|universitas|kampus|rumah sakit|rs|mall|restoran|kuliner|hotel|stasiun|bandara|pantai|taman|pasar|kampung|wisata|tempat)\b/,
      /\b(masjid|gereja|vihara|klenteng|museum|universitas|kampus|rumah sakit|rs|mall|restoran|kuliner|hotel|stasiun|bandara)\b.*\b(di semarang|sekitar|lain|ada|bisa)\b/,
      /\b(ada|apakah)\b.*\b(tempat ibadah|destinasi wisata|tempat wisata)\b/,
      /\b(mau cari|cari|mau lihat|lihat|mau kunjungi|kunjungi)\b.*\b(masjid|gereja|museum|universitas|rumah sakit|restoran|mall|hotel|kuliner)\b/,
      /\b(tempat makan|tempat ibadah|pusat belanja|oleh-oleh)\b/,
      /\b(wisata|rekreasi)\b.*\b(lain|lainnya|selain)\b/,
      /\b(Ada|tersedia)\b.*\b(sejenis|seperti|yang)\b/
    ];
    for (var i = 0; i < patterns.length; i++) {
      if (patterns[i].test(text)) return true;
    }
    return false;
  }

  function isDestinationNameQuery(query) {
    if (!_semarangCatalog) return false;
    var text = normalize(query);
    // Strip open/visit verbs to get the core destination name
    var stripped = text.replace(/\b(buka|open|jalankan|mulai|start|masuk|kunjungi|visit|lihat|cari|mau ke|mau buka|ingin ke)\b/g, '').trim();
    for (var i = 0; i < _semarangCatalog.destinations.length; i++) {
      var d = _semarangCatalog.destinations[i];
      var dName = normalize(d.name);
      // Exact match on full text or stripped text
      if (dName === text || dName === stripped) return true;
      // Partial match: destination name appears in the stripped query
      if (dName.length >= 5 && stripped.indexOf(dName) !== -1) return true;
      if (d.aliases) {
        for (var j = 0; j < d.aliases.length; j++) {
          var alias = normalize(d.aliases[j]);
          if (alias === text || alias === stripped) return true;
          if (alias.length >= 5 && stripped.indexOf(alias) !== -1) return true;
        }
      }
    }
    return false;
  }

  function isVirtualTourOpenQuery(query) {
    var text = normalize(query);
    return /\b(buka|open|jalankan|run|mulai|start|masuk|enter)\b/.test(text) &&
           /\b(tur|tour|virtual|360)\b/.test(text);
  }

  function isConfirmationResponse(query) {
    var text = normalize(query);
    // Only match short confirmation responses (1-3 words), not full sentences with destination names
    var wordCount = text.split(/\s+/).length;
    if (wordCount > 3) return false;
    return /^(ya|yes|oke|ok|baik|boleh|buka|ayo|yuk|siap|gas|mari|saya mau|mau|tentu|persis|benar|betul)$/.test(text) ||
           /\b(buka|ya|oke|boleh|ayo|yuk|masuk)\b/.test(text);
  }

  function isNumberSelection(query) {
    var text = normalize(query);
    var m = text.match(/^(yang\s+)?(nomor\s+)?(\d+|pertama|kedua|ketiga|keempat|kelima)$/);
    if (!m) return null;
    var words = { pertama: 1, kedua: 2, ketiga: 3, keempat: 4, kelima: 5 };
    if (m[3] && words[m[3]]) return words[m[3]] - 1;
    if (m[3]) { var n = parseInt(m[3], 10); return (n >= 1 && n <= 10) ? n - 1 : null; }
    return null;
  }

  function isBackToLawangSewu(query) {
    var text = normalize(query);
    return /\b(kembali|balik|lawang sewu|tempat ini|area ini|cerita.*tempat ini|balik cerita)\b/.test(text) &&
           !/\b(masjid|museum|gereja|universitas|rumah sakit|restoran|mall|hotel)\b/.test(text);
  }

  function validateTourUrl(url) {
    if (!url || typeof url !== 'string') return false;
    if (/^(javascript:|data:|file:)/.test(url)) return false;
    if (!/^https?:\/\//.test(url)) return false;
    if (url.indexOf('semarang.govirtual.id') === -1) return false;
    return true;
  }

  function resolveDestinationDiscovery(query) {
    if (!_semarangCatalog) return null;
    var text = normalize(query);

    // Check if user is selecting from last results
    var numSel = isNumberSelection(query);
    if (numSel !== null && _lastDestinationResults.length > 0 && numSel < _lastDestinationResults.length) {
      var selected = _lastDestinationResults[numSel];
      _pendingDestination = {
        destinationId: selected.id,
        name: selected.name,
        virtualTourUrl: selected.virtualTourUrl,
        virtualTourAvailable: selected.virtualTourAvailable
      };

      // Check if destination can be navigated to in the tour
      var navScene = findNavigableScene(selected.name);
      if (navScene) {
        navigate(navScene);
        return { reply: 'Saya bawa Anda ke ' + selected.name + '.', intent: 'navigation', confidence: 0.95, action: 'go_to_scene', scene: navScene.id };
      }

      var reply = selected.name + ' adalah salah satu destinasi di katalog Semarang.';
      if (selected.description) reply += ' ' + selected.description;
      if (selected.virtualTourAvailable) {
        reply += ' Tur virtualnya tersedia. Mau saya buka?';
      }
      return { reply: reply, intent: 'DESTINATION_DISCOVERY', confidence: 0.92, action: 'none', destination: selected };
    }

    // Check if user confirms opening virtual tour
    if (isConfirmationResponse(query) && _pendingDestination && _pendingDestination.virtualTourAvailable) {
      var dest = _pendingDestination;
      _pendingDestination = null;
      if (validateTourUrl(dest.virtualTourUrl)) {
        return {
          reply: 'Baik, saya buka tur virtual ' + dest.name + '.',
          intent: 'DESTINATION_DISCOVERY',
          confidence: 0.95,
          action: 'open_virtual_tour',
          destinationUrl: dest.virtualTourUrl,
          destinationId: dest.destinationId
        };
      }
    }

    // Check if user asks to open virtual tour directly
    if (isVirtualTourOpenQuery(query) && _pendingDestination && _pendingDestination.virtualTourAvailable) {
      var dest2 = _pendingDestination;
      _pendingDestination = null;
      if (validateTourUrl(dest2.virtualTourUrl)) {
        return {
          reply: 'Baik, saya buka tur virtual ' + dest2.name + '.',
          intent: 'DESTINATION_DISCOVERY',
          confidence: 0.95,
          action: 'open_virtual_tour',
          destinationUrl: dest2.virtualTourUrl,
          destinationId: dest2.destinationId
        };
      }
    }

    // Check if user asks about specific destination by name
    if (isDestinationNameQuery(query)) {
      var matches = searchDestinations(query, null);
      if (matches.length > 0) {
        var best = matches[0].destination;

        // Check if destination can be navigated to in the tour
        var navScene = findNavigableScene(best.name);
        if (navScene) {
          navigate(navScene);
          return { reply: 'Saya bawa Anda ke ' + best.name + '.', intent: 'navigation', confidence: 0.95, action: 'go_to_scene', scene: navScene.id };
        }

        _pendingDestination = {
          destinationId: best.id,
          name: best.name,
          virtualTourUrl: best.virtualTourUrl,
          virtualTourAvailable: best.virtualTourAvailable
        };
        _lastDestinationResults = matches.slice(0, 4).map(function (m) { return m.destination; });
        var reply2 = best.name + ' merupakan salah satu destinasi dalam katalog Semarang.';
        if (best.description) reply2 += ' ' + best.description;
        if (best.virtualTourAvailable) {
          reply2 += ' Tur virtualnya juga tersedia. Mau saya buka sekarang?';
        }
        return { reply: reply2, intent: 'DESTINATION_DISCOVERY', confidence: 0.93, action: 'none', destination: best };
      }
    }

    // Discovery: find destinations by category
    if (isDestinationDiscoveryQuery(query)) {
      var catId = detectDiscoveryCategory(query);
      var results = searchDestinations(query, catId);
      if (results.length > 0) {
        _lastDestinationResults = results.slice(0, 4).map(function (m) { return m.destination; });
        var catName = '';
        if (catId) {
          for (var i = 0; i < _semarangCatalog.categories.length; i++) {
            if (_semarangCatalog.categories[i].id === catId) { catName = _semarangCatalog.categories[i].name; break; }
          }
        }
        var reply3 = '';
        if (catName) {
          reply3 = 'Untuk destinasi bertema ' + catName.toLowerCase() + ' di Semarang, beberapa pilihan yang tersedia antara lain:\n';
        } else {
          reply3 = 'Beberapa destinasi di Semarang yang relevan:\n';
        }
        var vtHint = false;
        _lastDestinationResults.forEach(function (d, idx) {
          reply3 += (idx + 1) + '. ' + d.name;
          if (d.virtualTourAvailable && !vtHint) { vtHint = true; }
          if (idx < _lastDestinationResults.length - 1) reply3 += '\n';
        });
        if (vtHint) {
          reply3 += '\n\nBeberapa di antaranya memiliki tur virtual. Mau saya buka salah satu?';
        }
        return { reply: reply3, intent: 'DESTINATION_DISCOVERY', confidence: 0.90, action: 'none' };
      }
    }

    return null;
  }

  /* ================================================================
     SCENE IDENTITY LOADING (for answerability checks)
     ================================================================ */

  function loadSceneIdentity() {
    if (_sceneIdentityData) return Promise.resolve(_sceneIdentityData);
    if (_sceneIdentityLoading) return _sceneIdentityLoading;
    _sceneIdentityLoading = fetch('scene-identity.json')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.scenes) return null;
        _sceneIdentityData = data;
        return data;
      })
      .catch(function () { return null; });
    return _sceneIdentityLoading;
  }

  function getSceneIdentityEntry(sceneId) {
    if (!_sceneIdentityData || !_sceneIdentityData.scenes) return null;
    return _sceneIdentityData.scenes[sceneId] || null;
  }

  /* ================================================================
     KNOWLEDGE LOADING (for answerability checks)
     ================================================================ */

  function loadKnowledge() {
    if (_knowledgeData) return Promise.resolve(_knowledgeData);
    if (_knowledgeLoading) return _knowledgeLoading;
    _knowledgeLoading = fetch('knowledge.json')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.entries) return null;
        _knowledgeData = data;
        return data;
      })
      .catch(function () { return null; });
    return _knowledgeLoading;
  }

  /* ---- Explore menu knowledge state ---- */
  var _exploreMenuData = null;
  var _exploreMenuLoading = null;

  function loadExploreMenu() {
    if (_exploreMenuData) return Promise.resolve(_exploreMenuData);
    if (_exploreMenuLoading) return _exploreMenuLoading;
    _exploreMenuLoading = fetch('data/explore-menu-knowledge.json')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.entries) return null;
        _exploreMenuData = data;
        return data;
      })
      .catch(function () { return null; });
    return _exploreMenuLoading;
  }

  function resolveExploreEntity(query) {
    if (!_exploreMenuData || !_exploreMenuData.entries) return null;
    var text = normalize(query);
    if (!text) return null;
    var entries = _exploreMenuData.entries;
    // Tier 1: exact alias match
    for (var i = 0; i < entries.length; i++) {
      var entry = entries[i];
      var title = normalize(entry.title);
      var aliases = (entry.aliases || []).map(normalize);
      var all = [title].concat(aliases);
      for (var j = 0; j < all.length; j++) {
        if (all[j] && all[j].length >= 3 && text.indexOf(all[j]) !== -1) {
          return entry;
        }
      }
    }
    // Tier 2: token overlap
    var qTokens = text.split(/\s+/).filter(function (w) { return w.length > 2 && w !== 'lawang' && w !== 'sewu'; });
    if (!qTokens.length) return null;
    var best = null, bestScore = 0;
    for (var k = 0; k < entries.length; k++) {
      var e = entries[k];
      var needle = normalize(e.title + ' ' + (e.aliases || []).join(' '));
      var hits = 0;
      for (var t = 0; t < qTokens.length; t++) {
        if (needle.indexOf(qTokens[t]) !== -1) hits++;
      }
      var score = hits / qTokens.length;
      if (score > bestScore) { bestScore = score; best = e; }
    }
    return bestScore >= 0.6 ? best : null;
  }

  function findKnowledgeEntry(knowledgeId) {
    if (!_knowledgeData || !_knowledgeData.entries) return null;
    for (var i = 0; i < _knowledgeData.entries.length; i++) {
      if (_knowledgeData.entries[i].id === knowledgeId) return _knowledgeData.entries[i];
    }
    return null;
  }

  /* ================================================================
     PROVIDER HEALTH (for general knowledge fallback)
     ================================================================ */

  function loadProviderHealth() {
    if (_providerHealth) return Promise.resolve(_providerHealth);
    if (_providerHealthLoading) return _providerHealthLoading;
    _providerHealthLoading = fetch('api/ai-status.php')
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data) return null;
        _providerHealth = data;
        return data;
      })
      .catch(function () { return null; });
    return _providerHealthLoading;
  }

  function isAnyProviderAvailable() {
    if (!_providerHealth || !_providerHealth.providers) return false;
    var providers = _providerHealth.providers;
    for (var key in providers) {
      if (providers[key] && providers[key].configured && !providers[key].cooldown) return true;
    }
    return false;
  }

  /* ================================================================
     ANSWERABILITY ENGINE
     ================================================================
     Every suggested question must have enough evidence to produce
     a useful answer. This module evaluates whether a suggestion
     is answerable before displaying it.
     ================================================================ */

  var EVIDENCE_TYPES = {
    DIRECT_KNOWLEDGE: 'DIRECT_KNOWLEDGE',
    PLACE_KNOWLEDGE: 'PLACE_KNOWLEDGE',
    RELATED_KNOWLEDGE: 'RELATED_KNOWLEDGE',
    TOUR_KNOWLEDGE: 'TOUR_KNOWLEDGE',
    GRAPH: 'GRAPH',
    GENERAL_KNOWLEDGE: 'GENERAL_KNOWLEDGE',
    CURRENT_LOCATION: 'CURRENT_LOCATION',
    SEMARANG_CATALOG: 'SEMARANG_CATALOG',
    EXPLORE_MENU: 'EXPLORE_MENU',
    NONE: 'NONE'
  };

  var EVIDENCE_PRIORITY = {
    DIRECT_KNOWLEDGE: 1,
    PLACE_KNOWLEDGE: 2,
    RELATED_KNOWLEDGE: 3,
    TOUR_KNOWLEDGE: 4,
    GRAPH: 5,
    GENERAL_KNOWLEDGE: 6,
    CURRENT_LOCATION: 7,
    SEMARANG_CATALOG: 8,
    EXPLORE_MENU: 3,
    NONE: 99
  };

  /**
   * Evaluate whether a suggestion is answerable with available evidence.
   *
   * @param {Object} params
   * @param {string} params.question - The suggested question text
   * @param {string} params.intent - Classified intent type
   * @param {string} params.sceneId - Current scene ID
   * @param {Object} params.sceneIdentity - Scene identity entry from scene-identity.json
   * @param {Array} params.graphExits - Available navigation exits from current scene
   * @param {boolean} params.semarangCatalogLoaded - Whether Semarang catalog is available
   * @param {boolean} params.providerAvailable - Whether LLM provider is available
   * @returns {Object} { answerable, confidence, evidenceType, evidenceIds }
   */
  function evaluateSuggestionAnswerability(params) {
    var question = params.question || '';
    var intent = params.intent || '';
    var sceneId = params.sceneId || '';
    var sceneIdentity = params.sceneIdentity || null;
    var graphExits = params.graphExits || [];
    var semarangCatalogLoaded = params.semarangCatalogLoaded || false;
    var providerAvailable = params.providerAvailable || false;

    var result = {
      answerable: false,
      confidence: 0,
      evidenceType: EVIDENCE_TYPES.NONE,
      evidenceIds: []
    };

    if (!sceneIdentity) return result;

    var contentGrounding = sceneIdentity.contentGrounding || '';
    var relatedKnowledgeIds = sceneIdentity.relatedKnowledgeIds || [];
    var hasDescription = !!(sceneIdentity.description && sceneIdentity.description.trim());
    var hasHighlights = !!(sceneIdentity.highlights && sceneIdentity.highlights.length > 0);

    // P1: CURRENT_LOCATION — always answerable if scene identity exists
    if (intent === 'CURRENT_LOCATION' || /di mana saya/i.test(question)) {
      if (sceneIdentity.placeName) {
        result.answerable = true;
        result.confidence = 95;
        result.evidenceType = EVIDENCE_TYPES.CURRENT_LOCATION;
        result.evidenceIds = [sceneId];
        return result;
      }
    }

    // P2: NAVIGATION — answerable if graph has exits
    if (intent === 'NAVIGATION' || /ke mana|rekomendasi berikutnya|lanjut ke/i.test(question)) {
      if (graphExits.length > 0) {
        result.answerable = true;
        result.confidence = 90;
        result.evidenceType = EVIDENCE_TYPES.GRAPH;
        result.evidenceIds = graphExits.map(function (e) { return e.id; });
        return result;
      }
      return result;
    }

    // P3: SEMARANG DESTINATION DISCOVERY — answerable if catalog loaded
    if (intent === 'SEMARANG_DISCOVERY' || /destinasi.*semarang|wisata.*semarang/i.test(question)) {
      if (semarangCatalogLoaded) {
        result.answerable = true;
        result.confidence = 85;
        result.evidenceType = EVIDENCE_TYPES.SEMARANG_CATALOG;
        result.evidenceIds = [];
        return result;
      }
      return result;
    }

    // P3.5: EXPLORE MENU ENTITY — answerable if entity exists in explore menu
    if (intent === 'ENTITY_DESCRIPTION' || /apa itu|ceritakan|fungsi|menarik/i.test(question)) {
      var exploreMatch = resolveExploreEntity(question);
      if (exploreMatch && exploreMatch.description) {
        result.answerable = true;
        result.confidence = 92;
        result.evidenceType = EVIDENCE_TYPES.EXPLORE_MENU;
        result.evidenceIds = [exploreMatch.id];
        return result;
      }
    }

    // P4: TOUR-LEVEL KNOWLEDGE — answerable if shared-tour grounding
    if (intent === 'TOUR_DESCRIPTION' || /lawang sewu/i.test(question)) {
      if (contentGrounding === 'shared-tour' || contentGrounding === 'direct') {
        result.answerable = true;
        result.confidence = 80;
        result.evidenceType = EVIDENCE_TYPES.TOUR_KNOWLEDGE;
        result.evidenceIds = relatedKnowledgeIds;
        return result;
      }
      // Tour-level questions are generally answerable if we have any knowledge
      if (relatedKnowledgeIds.length > 0 || hasDescription) {
        result.answerable = true;
        result.confidence = 70;
        result.evidenceType = EVIDENCE_TYPES.TOUR_KNOWLEDGE;
        result.evidenceIds = relatedKnowledgeIds;
        return result;
      }
    }

    // P5: GENERAL KNOWLEDGE — answerable if provider available
    if (intent === 'GENERAL_KNOWLEDGE' || /arsitektur kolonial|kaca patri|NIS/i.test(question)) {
      if (providerAvailable) {
        result.answerable = true;
        result.confidence = 65;
        result.evidenceType = EVIDENCE_TYPES.GENERAL_KNOWLEDGE;
        result.evidenceIds = [];
        return result;
      }
      return result;
    }

    // P6: DIRECT SCENE/ENTITY KNOWLEDGE — answerable if direct or direct-likely grounding
    if (contentGrounding === 'direct' || contentGrounding === 'direct-likely') {
      if (hasDescription || relatedKnowledgeIds.length > 0) {
        result.answerable = true;
        result.confidence = 85;
        result.evidenceType = EVIDENCE_TYPES.DIRECT_KNOWLEDGE;
        result.evidenceIds = relatedKnowledgeIds;
        return result;
      }
    }

    // P7: PLACE KNOWLEDGE — answerable if shared-tour with description
    if (contentGrounding === 'shared-tour') {
      if (hasDescription && (hasHighlights || relatedKnowledgeIds.length > 0)) {
        result.answerable = true;
        result.confidence = 75;
        result.evidenceType = EVIDENCE_TYPES.PLACE_KNOWLEDGE;
        result.evidenceIds = relatedKnowledgeIds;
        return result;
      }
    }

    // P8: RELATED KNOWLEDGE — answerable if related knowledge exists
    if (contentGrounding === 'related-tour-knowledge') {
      if (relatedKnowledgeIds.length > 0) {
        result.answerable = true;
        result.confidence = 70;
        result.evidenceType = EVIDENCE_TYPES.RELATED_KNOWLEDGE;
        result.evidenceIds = relatedKnowledgeIds;
        return result;
      }
    }

    // CRITICAL: identity-only scenes should NOT generate scene-specific questions
    // Identity-only is ONLY sufficient for CURRENT_LOCATION and NAVIGATION
    if (contentGrounding === 'identity-only') {
      // Scene-specific questions are NOT answerable for identity-only scenes
      // unless they are about location or navigation (handled above)
      result.answerable = false;
      result.confidence = 0;
      result.evidenceType = EVIDENCE_TYPES.NONE;
      result.evidenceIds = [];
      return result;
    }

    return result;
  }

  /* ================================================================
     CANDIDATE SUGGESTION GENERATOR
     ================================================================
     Instead of generating questions first and checking later,
     derive questions FROM available evidence.
     ================================================================ */

  /**
   * Build answerability-aware contextual suggestions.
   *
   * @param {Object} params
   * @param {string} params.sceneId - Current scene ID
   * @returns {Array} Array of suggestion objects (max 4)
   */
  function buildContextualSuggestions(params) {
    var sceneId = params.sceneId || currentSceneId();
    var scene = sceneById(sceneId);
    var sceneIdentity = getSceneIdentityEntry(sceneId);
    var graphExits = graphExitIds(sceneId);
    var semarangLoaded = !!_semarangCatalog;
    var providerAvail = isAnyProviderAvailable();

    var suggestions = [];
    var usedIntents = {};

    // Helper to add a suggestion if answerable
    function tryAdd(question, intent, evidenceType, evidenceIds, confidence, label) {
      // Deduplicate by intent
      if (usedIntents[intent]) return;
      var evaluation = evaluateSuggestionAnswerability({
        question: question,
        intent: intent,
        sceneId: sceneId,
        sceneIdentity: sceneIdentity,
        graphExits: graphExits,
        semarangCatalogLoaded: semarangLoaded,
        providerAvailable: providerAvail
      });
      if (!evaluation.answerable) return;
      usedIntents[intent] = true;
      suggestions.push({
        id: sceneId + '-' + intent + '-' + suggestions.length,
        label: label || question,
        prompt: question,
        intent: intent,
        evidenceType: evaluation.evidenceType,
        evidenceIds: evaluation.evidenceIds,
        confidence: evaluation.confidence
      });
    }

    // ===== PRIORITY 1: DIRECT SCENE/ENTITY KNOWLEDGE =====
    if (sceneIdentity && (sceneIdentity.contentGrounding === 'direct' || sceneIdentity.contentGrounding === 'direct-likely')) {
      var placeName = sceneIdentity.placeName || sceneDisplayName(scene);
      // Use suggestedQuestions from scene-identity.json as candidates
      var sq = sceneIdentity.suggestedQuestions || [];
      for (var i = 0; i < sq.length && suggestions.length < 4; i++) {
        var q = sq[i];
        // Classify intent of this candidate question
        var candidateIntent = 'SCENE_DESCRIPTION';
        if (/apa itu|fungsi|kenapa|siapa|sejarah/i.test(q)) candidateIntent = 'ENTITY_DESCRIPTION';
        else if (/apa yang menarik|highlight/i.test(q)) candidateIntent = 'HIGHLIGHTS';
        else if (/ke mana|lanjut|rekomendasi/i.test(q)) candidateIntent = 'NAVIGATION';
        tryAdd(q, candidateIntent, null, null, 0, null);
      }
    }

    // ===== PRIORITY 2: PLACE KNOWLEDGE (shared-tour) =====
    if (sceneIdentity && sceneIdentity.contentGrounding === 'shared-tour' && suggestions.length < 4) {
      var tourQuestions = sceneIdentity.suggestedQuestions || [];
      for (var j = 0; j < tourQuestions.length && suggestions.length < 4; j++) {
        var tq = tourQuestions[j];
        var tourIntent = 'TOUR_DESCRIPTION';
        if (/kenapa|asal nama/i.test(tq)) tourIntent = 'NAME_ORIGIN';
        else if (/apa yang menarik/i.test(tq)) tourIntent = 'HIGHLIGHTS';
        else if (/hubungan|kereta api/i.test(tq)) tourIntent = 'TOUR_CONNECTION';
        else if (/ke mana|lanjut/i.test(tq)) tourIntent = 'NAVIGATION';
        tryAdd(tq, tourIntent, null, null, 0, null);
      }
    }

    // ===== PRIORITY 3: RELATED KNOWLEDGE =====
    if (sceneIdentity && sceneIdentity.contentGrounding === 'related-tour-knowledge' && suggestions.length < 4) {
      var relQuestions = sceneIdentity.suggestedQuestions || [];
      for (var k = 0; k < relQuestions.length && suggestions.length < 4; k++) {
        tryAdd(relQuestions[k], 'RELATED_KNOWLEDGE', null, null, 0, null);
      }
    }

    // ===== PRIORITY 4: CURRENT LOCATION (always available) =====
    if (suggestions.length < 4 && sceneIdentity && sceneIdentity.placeName) {
      tryAdd('Di mana saya?', 'CURRENT_LOCATION', null, null, 0, 'Di mana saya?');
    }

    // ===== PRIORITY 5: NAVIGATION =====
    if (suggestions.length < 4 && graphExits.length > 0) {
      tryAdd('Ke mana sebaiknya saya lanjut?', 'NAVIGATION', null, null, 0, 'Ke mana berikutnya?');
    }

    // ===== PRIORITY 6: TOUR-LEVEL FALLBACK (for weak scenes) =====
    if (suggestions.length < 4 && sceneIdentity && sceneIdentity.contentGrounding === 'identity-only') {
      // For identity-only scenes, use tour-level fallback questions
      var fallbackQuestions = [
        { q: 'Kenapa disebut Lawang Sewu?', intent: 'NAME_ORIGIN' },
        { q: 'Apa yang menarik dari Lawang Sewu?', intent: 'TOUR_HIGHLIGHTS' },
        { q: 'Apa sejarah Lawang Sewu?', intent: 'TOUR_HISTORY' }
      ];
      for (var f = 0; f < fallbackQuestions.length && suggestions.length < 4; f++) {
        var fb = fallbackQuestions[f];
        // These are tour-level questions, answerable if any knowledge exists
        var fbEval = evaluateSuggestionAnswerability({
          question: fb.q,
          intent: fb.intent,
          sceneId: sceneId,
          sceneIdentity: { contentGrounding: 'shared-tour', relatedKnowledgeIds: ['skin:welcome-lawang-sewu'], description: 'Lawang Sewu' },
          graphExits: graphExits,
          semarangCatalogLoaded: semarangLoaded,
          providerAvailable: providerAvail
        });
        if (fbEval.answerable) {
          tryAdd(fb.q, fb.intent, null, null, 0, null);
        }
      }
    }

    // ===== PRIORITY 6.5: EXPLORE MENU ENTITIES =====
    if (suggestions.length < 4 && _exploreMenuData && _exploreMenuData.entries) {
      var exploreEntries = _exploreMenuData.entries;
      for (var ei = 0; ei < exploreEntries.length && suggestions.length < 4; ei++) {
        var ee = exploreEntries[ei];
        var eq = 'Apa itu ' + ee.title + '?';
        var eIntent = 'ENTITY_DESCRIPTION';
        tryAdd(eq, eIntent, null, null, 0, null);
      }
    }

    // ===== PRIORITY 7: SEMARANG DISCOVERY FALLBACK =====
    if (suggestions.length < 4 && semarangLoaded) {
      tryAdd('Ada destinasi lain di Semarang?', 'SEMARANG_DISCOVERY', null, null, 0, 'Destinasi Semarang');
    }

    // ===== PRIORITY 8: GENERAL KNOWLEDGE FALLBACK =====
    if (suggestions.length < 4 && providerAvail) {
      var genQuestions = [
        { q: 'Apa itu arsitektur kolonial?', intent: 'GENERAL_KNOWLEDGE' },
        { q: 'Bagaimana fungsi kaca patri pada bangunan kolonial?', intent: 'GENERAL_KNOWLEDGE' }
      ];
      for (var g = 0; g < genQuestions.length && suggestions.length < 4; g++) {
        tryAdd(genQuestions[g].q, genQuestions[g].intent, null, null, 0, null);
      }
    }

    // Ensure max 4 suggestions
    return suggestions.slice(0, 4);
  }

  function getSkinPlayer() {
    if (window.pano && typeof window.pano.setLanguage === 'function') return window.pano;
    if (window.skin && window.skin.player && typeof window.skin.player.setLanguage === 'function') return window.skin.player;
    if (window.pano && window.pano.skinObj && window.pano.skinObj.player && typeof window.pano.skinObj.player.setLanguage === 'function') return window.pano.skinObj.player;
    return null;
  }

  function findSkinElement(key) {
    var skinObj = window.skin || (window.pano && window.pano.skinObj) || null;
    if (!skinObj || typeof skinObj.findElements !== 'function') return null;
    var direct = skinObj.findElements(key, false);
    if (direct && direct.length) return direct[0];
    var fuzzy = skinObj.findElements(key, true);
    return fuzzy && fuzzy.length ? fuzzy[0] : null;
  }

  function runSkinAction(action) {
    var player = getSkinPlayer();
    var skinObj = window.skin || (window.pano && window.pano.skinObj) || null;

    function openSkinPopup(buttonKey, popupKey) {
      var button = skinObj && skinObj[buttonKey];
      var popup = skinObj && skinObj[popupKey];
      if (button && typeof button.onclick === 'function') button.onclick();
      if (!popup) return false;
      popup.ggVisible = true;
      popup.style.opacity = '1';
      popup.style.visibility = 'inherit';
      popup.style.pointerEvents = 'auto';
      return true;
    }

    switch (action) {
      case 'set_language_en':
        if (player) player.setLanguage('en');
        return true;
      case 'set_language_id':
        if (player) player.setLanguage('id');
        return true;
      case 'set_language_nl':
        if (player) player.setLanguage('nl');
        return true;
      case 'set_language_ar':
        if (player) player.setLanguage('ar');
        return true;
      case 'set_language_zh':
        if (player) player.setLanguage('zh-CN');
        return true;
      case 'open_explore':
        return openSkinPopup('_jelajah', '_url_popupdiscover');
      case 'open_menu':
        return openSkinPopup('_icon_menu', '_url_popupmenu') || openSkinPopup('_menu', '_url_popupmenu');
      case 'open_information':
        return openSkinPopup('_icon_info', '_url_popupinformasi');
      case 'open_public_service':
        if (skinObj && skinObj._icon_public && typeof skinObj._icon_public.onclick === 'function') skinObj._icon_public.onclick();
        return true;
      case 'play_360':
        if (skinObj && skinObj._btn360 && typeof skinObj._btn360.onclick === 'function') skinObj._btn360.onclick();
        return true;
      case 'open_category':
        if (skinObj && skinObj._button_kategori && typeof skinObj._button_kategori.onclick === 'function') {
          skinObj._button_kategori.onclick();
        }
        if (skinObj && skinObj._button_kategori_mobile && typeof skinObj._button_kategori_mobile.onclick === 'function') {
          skinObj._button_kategori_mobile.onclick();
        }
        if (skinObj && skinObj._url_popupkategori) {
          skinObj._url_popupkategori.style.opacity = '1';
          skinObj._url_popupkategori.style.visibility = 'inherit';
          skinObj._url_popupkategori.ggVisible = true;
        }
        if (skinObj && skinObj._popup_url && skinObj._popup_url.ggTextDiv) {
          skinObj._popup_url.ggTextDiv.innerHTML = '<iframe src="../webview/kategori-id.html" width="100%" style="height:100%;min-height:100%;border-radius:10px;border:0;pointer-events:auto;opacity:1" frameborder="0"></iframe>';
        }
        return true;
      default:
        return false;
    }
  }

  function skinActionFromQuery(query) {
    var text = normalize(query);
    // Language change requires explicit command verb + language name.
    // Standalone keywords only for languages unlikely in Indonesian sentences.
    if (/(ganti|ubah|pakai|switch|change)\s+(bahasa\s+)?(inggris|english)|^english$/.test(text)) return { action: 'set_language_en', reply: 'Saya ubah bahasa ke English.' };
    if (/(ganti|ubah|pakai|switch|change)\s+(bahasa\s+)?indonesia|^bahasa indonesia$/.test(text)) return { action: 'set_language_id', reply: 'Saya ubah bahasa ke Indonesia.' };
    if (/(ganti|ubah|pakai|switch|change)\s+(bahasa\s+)?(belanda|dutch)|^dutch$/.test(text)) return { action: 'set_language_nl', reply: 'Saya ubah bahasa ke Dutch.' };
    if (/(ganti|ubah|pakai|switch|change)\s+(bahasa\s+)?(arab|arabic)|^arabic$/.test(text)) return { action: 'set_language_ar', reply: 'Saya ubah bahasa ke Arabic.' };
    if (/(ganti|ubah|pakai|switch|change)\s+(bahasa\s+)?(china|mandarin|chinese|zh)|^chinese$/.test(text)) return { action: 'set_language_zh', reply: 'Saya ubah bahasa ke Chinese.' };
    if (/(buka|open|tampilkan|show).*(kategori|category|menu kategori)|\bkategori\b/.test(text)) return { action: 'open_category', reply: 'Saya buka menu kategori virtual tour.' };
    if (/(buka|open|tampilkan|show).*(jelajah|explore|discover)|^(jelajah|explore|discover)$/.test(text)) return { action: 'open_explore', reply: 'Saya buka Jelajah. Menu ini menampilkan pilihan destinasi atau objek yang dapat dijelajahi dalam virtual tour.' };
    if (/(buka|open|tampilkan|show).*(layanan publik|public service)|^(layanan publik|public service)$/.test(text)) return { action: 'open_public_service', reply: 'Saya buka Layanan Publik di tab baru.' };
    if (/(buka|open|tampilkan|show).*(informasi|information|info)|^(informasi|information|info)$/.test(text)) return { action: 'open_information', reply: 'Saya buka panel Informasi.' };
    if (/(buka|open|tampilkan|show).*(menu utama|main menu)|^(menu|main menu)$/.test(text)) return { action: 'open_menu', reply: 'Saya buka Menu utama.' };
    if (/(putar|jalankan|play|mulai|start).*(360|video)|^(360|video 360)$/.test(text)) return { action: 'play_360', reply: 'Saya jalankan tampilan 360°.' };
    if (/(apa itu|fungsi|tentang).*(jelajah|explore|discover)/.test(text)) return { action: 'none', reply: 'Jelajah membuka daftar destinasi atau objek yang dapat dipilih dalam virtual tour.' };
    if (/(button|tombol|menu|fitur).*(apa saja|ada apa|tersedia|virtual tour)|apa saja.*(button|tombol|menu|fitur)/.test(text)) return { action: 'none', reply: 'Fitur virtual tour: Menu utama, Layanan Publik, Informasi, Jelajah, Chatbot, Kategori, mode 360°, pilihan bahasa, peta, kompas, zoom, dan hotspot perpindahan panorama.' };
    return null;
  }

  function currentSceneId() {
    if (typeof window.pano.getCurrentNode === 'function') return window.pano.getCurrentNode();
    var hash = location.hash.split(',')[0].replace('#', '');
    return hash || (data.scenes.length ? data.scenes[0].id : '');
  }

  function findSceneMatches(query) {
    var text = normalize(query);
    return data.scenes
      .map(function (scene) {
        var terms = [scene.name].concat(scene.aliases || []);
        var score = terms.reduce(function (best, term) {
          var normalizedTerm = normalize(term);
          return text.indexOf(normalizedTerm) !== -1 ? Math.max(best, normalizedTerm.length) : best;
        }, 0);
        return { scene: scene, score: score };
      })
      .filter(function (match) { return match.score > 0; })
      .sort(function (a, b) { return b.score - a.score; });
  }

  function findScene(query) {
    return findSceneMatches(query)[0];
  }

  function isExplicitSceneNavigation(query) {
    var text = normalize(query);
    return contextualSceneMatches(query).length > 0 && /(?:^|\b)(?:ke|menuju|pergi ke|bawa(?:\s+saya)?\s+ke|antar(?:\s+saya)?\s+ke|arahkan(?:\s+saya)?\s+ke|(?:saya\s+)?mau\s+(?:lihat|liat|ke|kelihat)|(?:saya\s+)?ingin\s+(?:lihat|liat|ke|melihat)|(?:saya\s+)?pengen\s+(?:lihat|liat|ke)|go to|take me to)\b/.test(text);
  }

  function findHotspot(query, sceneId) {
    var text = normalize(query);
    return data.hotspots
      .filter(function (hotspot) { return hotspot.scene === sceneId; })
      .map(function (hotspot) {
        var term = normalize(hotspot.title);
        return { hotspot: hotspot, score: text.indexOf(term) !== -1 ? term.length : 0 };
      })
      .sort(function (a, b) { return b.score - a.score; })[0];
  }

  function findRouteMatches(query) {
    var text = normalize(query);
    return (data.routes || []).map(function (route) {
      var score = route.aliases.reduce(function (best, alias) {
        var term = normalize(alias);
        return text.indexOf(term) !== -1 ? Math.max(best, term.length) : best;
      }, 0);
      return { route: route, score: score };
    }).filter(function (match) { return match.score > 0; }).sort(function (a, b) { return b.score - a.score; });
  }

  function findRoute(query) {
    return findRouteMatches(query)[0];
  }

  function buildSceneGraph() {
    var graph = {};
    data.scenes.forEach(function (scene) { graph[scene.id] = []; });
    data.hotspots.forEach(function (hotspot) {
      if (graph[hotspot.scene] && hotspot.target !== hotspot.scene && graph[hotspot.scene].indexOf(hotspot.target) === -1) graph[hotspot.scene].push(hotspot.target);
    });
    return graph;
  }

  function findScenePath(fromSceneId, toSceneId) {
    var graph = buildSceneGraph();
    if (!graph[fromSceneId] || !graph[toSceneId]) return [];
    var queue = [[fromSceneId]];
    var visited = {};
    visited[fromSceneId] = true;
    while (queue.length) {
      var path = queue.shift();
      var node = path[path.length - 1];
      if (node === toSceneId) return path;
      graph[node].forEach(function (next) {
        if (!visited[next]) { visited[next] = true; queue.push(path.concat(next)); }
      });
    }
    return [];
  }

  function routeBetweenScenes(fromQuery, toQuery) {
    var fromMatch = findSceneMatches(fromQuery)[0];
    var toMatch = findSceneMatches(toQuery)[0];
    if (!fromMatch || !toMatch) return null;
    var path = findScenePath(fromMatch.scene.id, toMatch.scene.id);
    return path.length ? { route: null, fromScene: fromMatch.scene, toScene: toMatch.scene, path: path } : null;
  }

  function remember(role, text) {
    history.push({ role: role, text: text, scene: currentSceneId() });
    history = history.slice(-8);
  }

  function updateConversationState(result, query) {
    var referencedSceneId = result && result.conversation_update ? result.conversation_update.referenced_scene_id : null;
    var referencedEntityId = result && result.conversation_update ? result.conversation_update.referenced_entity_id : null;
    var recommendedRouteId = result && result.conversation_update ? result.conversation_update.recommended_route_id : null;
    if (query) conversationState.lastEntity = result && result.entities && result.entities[0] ? result.entities[0] : query;
    if (referencedEntityId) conversationState.lastReferencedEntity = referencedEntityId;
    if (referencedSceneId) {
      conversationState.lastReferencedDestination = referencedSceneId;
      conversationState.lastNavigableSceneId = referencedSceneId;
      conversationState.lastRecommendedSceneId = referencedSceneId;
    }
    if (recommendedRouteId) conversationState.recommendedRouteId = recommendedRouteId;
    if (result && result.recommendation_target) conversationState.lastRecommendedDestination = result.recommendation_target;
    conversationState.lastReply = result && result.reply ? result.reply : conversationState.lastReply;
    conversationState.lastIntent = result && result.intent ? result.intent : conversationState.lastIntent;
    if (result && result.route) conversationState.lastRoute = result.route;
    if (result && result.active_route_id) conversationState.activeRouteId = result.active_route_id;
    if (result && result.active_route_scene_ids) conversationState.activeRouteSceneIds = result.active_route_scene_ids;
    if (result && result.active_route_step !== undefined && result.active_route_step !== null) conversationState.activeRouteStep = result.active_route_step;
    if (result && result.profile_updates) {
      var updates = result.profile_updates;
      if (updates.transport) conversationState.visitorProfile.transport = updates.transport;
      if (typeof updates.traveling_with_children === 'boolean') conversationState.visitorProfile.travelingWithChildren = updates.traveling_with_children;
      if (updates.service_preference) conversationState.visitorProfile.servicePreference = updates.service_preference;
      if (updates.destination_preference) conversationState.visitorProfile.destinationPreference = updates.destination_preference;
      if (updates.accessibility_needs) conversationState.visitorProfile.accessibilityNeeds = updates.accessibility_needs.slice(0, 10);
      if (updates.interests) conversationState.visitorProfile.interests = updates.interests.slice(0, 10);
    }
    if (result && result.scene) conversationState.lastSceneId = result.scene;
    else conversationState.lastSceneId = currentSceneId();
    if (result && result.mentioned_scene_ids && result.mentioned_scene_ids.length) conversationState.lastMentionedSceneIds = result.mentioned_scene_ids.slice(0, 10);
    if (conversationState.visitedSceneIds.indexOf(conversationState.lastSceneId) === -1) conversationState.visitedSceneIds.push(conversationState.lastSceneId);

    // Typed entity tracking for reference resolution
    if (result && result.entities && result.entities.length) {
      var topEntity = result.entities[0];
      var entityType = topEntity.type || 'entity';
      var entityName = topEntity.name || '';
      conversationState.lastDiscussedEntity = entityName;
      conversationState.lastDiscussedEntityType = entityType;
      // Track navigable entities separately
      if (result.action === 'go_to_scene' || entityType === 'destination' || entityType === 'explore-place') {
        conversationState.lastNavigableEntity = entityName;
      }
    }
    // Track public service entities
    if (result && result.intent === 'PUBLIC_SERVICE' && result.entities && result.entities.length) {
      conversationState.lastPublicServiceEntity = result.entities[0];
    }
    // Public service state for follow-ups
    if (result && result.session_update && result.session_update.lastPublicServiceResults) {
      _lastPublicServiceResults = result.session_update.lastPublicServiceResults;
    }
  }

  function contextualSceneMatches(query) {
    var matches = findSceneMatches(query);
    if (matches.length) return matches;
    var text = normalize(query);
    if (!text) return [];
    var results = data.scenes.map(function (scene) {
      var terms = [normalize(scene.name)].concat((scene.aliases || []).map(normalize));
      var bestScore = 0;
      var isExactName = false;
      for (var i = 0; i < terms.length; i++) {
        var term = terms[i];
        if (!term || term.length < 3) continue;
        // Exact match on name (highest priority)
        if (i === 0 && term === text) { bestScore = 200; isExactName = true; break; }
        // Name contains query or query contains name
        if (term.indexOf(text) !== -1 || text.indexOf(term) !== -1) {
          var s = Math.min(term.length, text.length) || 1;
          if (s > bestScore) bestScore = s;
        }
        // Word-level match (min 3 chars per word)
        var parts = term.split(' ');
        for (var j = 0; j < parts.length; j++) {
          if (parts[j] && parts[j].length >= 3 && (parts[j].indexOf(text) !== -1 || text.indexOf(parts[j]) !== -1)) {
            if (parts[j].length > bestScore) bestScore = parts[j].length;
          }
        }
      }
      return { scene: scene, score: bestScore, isExactName: isExactName };
    }).filter(function (m) { return m.score > 0; })
      .sort(function (a, b) {
        if (a.isExactName && !b.isExactName) return -1;
        if (!a.isExactName && b.isExactName) return 1;
        return b.score - a.score;
      });
    return results;
  }

  /* ---- Drone navigation state ---- */
  var _pendingDroneChoice = null;

  /* ---- Public service state ---- */
  var _lastPublicServiceResults = [];
  var _pendingPublicService = null;

  function resolveContextualIntent(query, active) {
    var text = normalize(query);
    var pronounFollowUp = /\b(di sana|kesana|ke sana|yang tadi|tempat tadi|area itu|scene itu)\b/.test(text) && !/\b(apa|bagaimana|kenapa|mengapa|siapa|kapan|berapa|dimana|di mana|jelaskan|ceritakan|deskripsikan)\b/.test(text);
    var continueRoute = /\b(lanjut|berikutnya|next|selanjutnya)\b/.test(text);
    var wantsRoute = /\b(rute|jalur|ikut|ikuti|bawa|antar|arahkan)\b/.test(text);
    var wantsFacility = /\b(nunggu|menunggu|tempat nunggu|nunggunya|waiting|rest|family|anak|keluarga)\b/.test(text);

    // --- Drone navigation ---
    var wantsDrone = /\bdrone\b/i.test(text);
    if (wantsDrone) {
      // Check if user is choosing between options
      if (_pendingDroneChoice) {
        var choice = _pendingDroneChoice;
        _pendingDroneChoice = null;
        if (/\b(utama|area utama|main|depan|halaman)\b/.test(text)) {
          var mainScene = sceneById(choice.mainId);
          if (mainScene) {
            navigate(mainScene);
            return { reply: 'Saya arahkan ke view drone Area Utama Lawang Sewu.', intent: 'navigate', confidence: 0.95, action: 'go_to_scene', scene: mainScene.id };
          }
        }
        if (/\b(balkon|atas|elevated|view)\b/.test(text)) {
          var balkonScene = sceneById(choice.balkonId);
          if (balkonScene) {
            navigate(balkonScene);
            return { reply: 'Saya arahkan ke view drone Balkon.', intent: 'navigate', confidence: 0.95, action: 'go_to_scene', scene: balkonScene.id };
          }
        }
        // Default: go to balkon
        var defaultScene = sceneById(choice.balkonId);
        if (defaultScene) {
          navigate(defaultScene);
          return { reply: 'Saya arahkan ke view drone Balkon.', intent: 'navigate', confidence: 0.95, action: 'go_to_scene', scene: defaultScene.id };
        }
      }

      // Offer drone options
      var mainDay = sceneById('node51');
      var mainNight = sceneById('node50');
      var balkonDay = sceneById('node53');
      var balkonNight = sceneById('node52');
      var mainScene = active && active.variant === 'night' ? mainNight : mainDay;
      var balkonScene = active && active.variant === 'night' ? balkonNight : balkonDay;
      if (!mainScene) mainScene = mainDay || mainNight;
      if (!balkonScene) balkonScene = balkonDay || balkonNight;

      if (mainScene && balkonScene) {
        _pendingDroneChoice = { mainId: mainScene.id, balkonId: balkonScene.id };
        pendingClarification = { type: 'drone', options: ['Drone Area Utama', 'Drone Balkon'] };
        return {
          reply: 'Ada 2 view drone yang tersedia:\n1. Drone Area Utama — pemandangan halaman dari atas\n2. Drone Balkon — pemandangan dari balkon\n\nMau yang mana?',
          intent: 'clarify',
          confidence: 0.90,
          needs_clarification: true,
          clarification_options: ['Drone Area Utama', 'Drone Balkon'],
          action: 'none'
        };
      }
    }

    if (continueRoute && conversationState.activeRouteSceneIds && conversationState.activeRouteSceneIds.length) {
      var currentIndex = conversationState.activeRouteSceneIds.indexOf(currentSceneId());
      var nextSceneId = currentIndex >= 0 ? conversationState.activeRouteSceneIds[currentIndex + 1] : conversationState.activeRouteSceneIds[0];
      var nextScene = nextSceneId ? sceneById(nextSceneId) : null;
      if (nextScene) {
        return { reply: 'Saya lanjutkan ke ' + sceneDisplayName(nextScene) + '.', intent: 'navigate', confidence: 0.92, action: 'go_to_scene', scene: nextScene.id };
      }
    }

    if (pronounFollowUp) {
      var targetSceneId = (conversationState.lastRecommendedDestination && conversationState.lastRecommendedDestination.sceneId) || conversationState.lastReferencedDestination || conversationState.lastRecommendedSceneId || conversationState.lastNavigableSceneId || null;
      if (!targetSceneId && conversationState.activeRouteSceneIds && conversationState.activeRouteSceneIds.length) {
        var activeIndex = conversationState.activeRouteSceneIds.indexOf(currentSceneId());
        targetSceneId = activeIndex >= 0 ? conversationState.activeRouteSceneIds[activeIndex + 1] : conversationState.activeRouteSceneIds[0];
      }
      if (targetSceneId) {
        var targetScene = sceneById(targetSceneId);
        if (targetScene) return { reply: 'Saya bawa Anda ke ' + sceneDisplayName(targetScene) + '. ' + sceneSummary(targetScene), intent: 'navigate', confidence: 0.88, action: 'go_to_scene', scene: targetScene.id };
      }
      return { reply: 'Maksud Anda tujuan yang mana?', intent: 'clarify', confidence: 0.4, needs_clarification: true, clarification_options: [], action: 'none' };
    }

    if (wantsFacility) {
      var profile = conversationState.visitorProfile || {};
      var facilityMatches = contextualSceneMatches(query).filter(function (match) { return /anak|keluarga|tunggu|waiting|service|lobi/.test(normalize(match.scene.name + ' ' + (match.scene.aliases || []).join(' '))); });
      if (!facilityMatches.length && profile.travelingWithChildren) facilityMatches = data.scenes.map(function (scene) { return { scene: scene, score: /anak|keluarga|lobi|tunggu/.test(normalize(scene.name + ' ' + (scene.aliases || []).join(' '))) ? 1 : 0 }; }).filter(function (match) { return match.score > 0; });
      if (facilityMatches.length > 1 && facilityMatches[0].score === facilityMatches[1].score) return clarify(facilityMatches, 'scene');
      if (facilityMatches[0]) {
        var facilityScene = facilityMatches[0].scene;
        conversationState.lastRecommendedSceneId = facilityScene.id;
        conversationState.lastNavigableSceneId = facilityScene.id;
        return { reply: 'Untuk menunggu, saya rekomendasikan ' + sceneDisplayName(facilityScene) + '. ' + sceneSummary(facilityScene), intent: 'facility_recommendation', confidence: 0.93, action: 'show_facility', scene: facilityScene.id };
      }
      return { reply: 'Saya belum menemukan fasilitas tunggu yang sesuai di data tur.', intent: 'facility_recommendation', confidence: 0.5, action: 'none' };
    }

    if (wantsRoute || /\b(mobil|kendaraan|anak|keluarga|eksekutif|vip|lansia|disabilitas)\b/.test(text)) {
      var routeMatches = findRouteMatches(query);
      if (routeMatches.length > 1 && routeMatches[0].score === routeMatches[1].score) return clarify(routeMatches, 'route');
      if (routeMatches[0]) {
        var route = routeMatches[0].route;
        var firstScene = route.scenes[0];
        conversationState.recommendedRouteId = route.id;
        conversationState.lastRecommendedSceneId = firstScene;
        conversationState.lastNavigableSceneId = firstScene;
        // Navigate directly to first scene
        var firstSceneObj = sceneById(firstScene);
        if (firstSceneObj) {
          navigate(firstSceneObj);
          return { reply: 'Saya arahkan ke ' + sceneDisplayName(firstSceneObj) + '. Urutan: ' + route.scenes.map(function (sceneId) { var s = sceneById(sceneId); return s ? sceneDisplayName(s) : sceneId; }).join(' → ') + '.', intent: 'navigate', confidence: 0.94, action: 'go_to_scene', scene: firstScene };
        }
        return { reply: 'Saya rekomendasikan ' + route.name + '. Urutannya: ' + route.scenes.map(function (sceneId) { var s = sceneById(sceneId); return s ? sceneDisplayName(s) : sceneId; }).join(' → ') + '.', intent: 'route_recommendation', confidence: 0.94, action: 'none', route: route.scenes };
      }
    }

    // Skip navigation for informational questions
    var isInfoQueryNav = /\b(pukul|jam|berapa|buka|tutup|harga|tiket|biaya|sejarah|kenapa|mengapa|apa itu|apa yang|ceritakan|jelaskan|deskripsikan|arti|makna|fungsi|menarik|unik|bagaimana|dimana|di mana|kapan|siapa|hubungan|kaitan|hubungannya|berkaitan)\b/.test(text);
    if (!isInfoQueryNav && /(?:^|\b)(?:ke|menuju|pergi ke|bawa(?:\s+saya)?\s+ke|antar(?:\s+saya)?\s+ke|arahkan(?:\s+saya)?\s+ke|(?:saya\s+)?mau\s+(?:lihat|liat|ke|kelihat)|(?:saya\s+)?ingin\s+(?:lihat|liat|ke|melihat)|(?:saya\s+)?pengen\s+(?:lihat|liat|ke)|go to|take me to)\b/.test(text)) {
      var sceneMatches = contextualSceneMatches(query);
      if (sceneMatches.length > 0) {
        conversationState.lastReferencedDestination = sceneMatches[0].scene.id;
        conversationState.lastNavigableSceneId = sceneMatches[0].scene.id;
        navigate(sceneMatches[0].scene);
        return { reply: 'Saya bawa Anda ke ' + sceneDisplayName(sceneMatches[0].scene) + '. ' + sceneSummary(sceneMatches[0].scene), intent: 'navigate', confidence: 0.95, action: 'go_to_scene', scene: sceneMatches[0].scene.id };
      }
    }

    if (/\b(di sini|area ini|tempat ini)\b/.test(text) && active && !/\b(kenapa|mengapa|apa|siapa|kapan|bagaimana|berapa|dimana|di mana|ceritakan|jelaskan|deskripsikan|tentang)\b/.test(text)) {
      return {
        reply: sceneSummary(active),
        intent: 'area_info',
        confidence: 0.95,
        scene: active.id,
        evidenceTypes: ['structural'].concat(active.description ? ['curated'] : []),
        capabilityFlags: contentCapabilities(active)
      };
    }

    return null;
  }

  function clarify(matches, type) {
    var options = matches.slice(0, 3).map(function (match) { return type === 'route' ? match.route.name : sceneDisplayName(match.scene); });
    pendingClarification = { type: type, options: options };
    return {
      reply: 'Maksud Anda yang mana?',
      intent: 'clarify',
      confidence: 0.4,
      needs_clarification: true,
      clarification_options: options,
      action: 'none'
    };
  }

  function navigate(scene) {
    window.pano.openNext('{' + scene.id + '}', '');
    location.hash = scene.id;
  }

  function focusHotspot(hotspot) {
    if (typeof window.pano.moveTo === 'function') {
      window.pano.moveTo(hotspot.pan + '/' + hotspot.tilt + '/70', 1);
    }
  }

  function sceneById(sceneId) {
    return data.scenes.find(function (scene) { return scene.id === sceneId; });
  }

  function findNavigableScene(name) {
    var text = normalize(name);
    if (!text) return null;
    // Search scenes by displayName, name, or aliases
    var best = null;
    var bestScore = 0;
    for (var i = 0; i < data.scenes.length; i++) {
      var scene = data.scenes[i];
      var score = 0;
      var sceneName = normalize(sceneDisplayName(scene));
      var sceneRaw = normalize(scene.name || '');
      // Exact match
      if (text === sceneName || text === sceneRaw) { score = 100; }
      // Scene name contains query
      else if (sceneName.length >= 3 && text.indexOf(sceneName) !== -1) { score = sceneName.length * 2; }
      // Query contains scene name
      else if (sceneName.length >= 3 && sceneName.indexOf(text) !== -1) { score = text.length * 2; }
      // Alias match
      var aliases = scene.aliases || [];
      for (var j = 0; j < aliases.length; j++) {
        var alias = normalize(aliases[j]);
        if (alias.length >= 3 && text.indexOf(alias) !== -1) { score = Math.max(score, alias.length + 5); }
        if (alias.length >= 3 && alias.indexOf(text) !== -1) { score = Math.max(score, text.length + 5); }
      }
      if (score > bestScore) { bestScore = score; best = scene; }
    }
    return bestScore >= 5 ? best : null;
  }

  var _sceneRegistry = data._sceneRegistry || {};

  function getSceneIdentity(sceneId) {
    return _sceneRegistry[sceneId] || null;
  }

  function getVisitorSceneName(sceneId) {
    var reg = _sceneRegistry[sceneId];
    if (reg && reg.placeName) return normalizeVisitorLabel(reg.placeName);
    var scene = sceneById(sceneId);
    return scene ? sceneDisplayName(scene) : 'area ini';
  }

  function sceneSummary(scene) {
    if (!scene) return '';
    var parts = [];
    if (scene.description) parts.push(scene.description);
    var highlights = (scene.highlights || []).join(', ');
    var facilities = (scene.facilities || []).join(', ');
    var whoFor = (scene.whoFor || []).join(', ');
    if (highlights) parts.push('Yang menonjol di area ini: ' + highlights + '.');
    if (facilities) parts.push('Fasilitas atau fungsi area ini: ' + facilities + '.');
    if (whoFor) parts.push('Cocok untuk: ' + whoFor + '.');
    return parts.join(' ');
  }

  function sceneDisplayName(scene) {
    var name = '';
    if (scene && scene.id && _sceneRegistry[scene.id]) name = _sceneRegistry[scene.id].placeName;
    else name = (scene && (scene.displayName || scene.rawName || scene.name)) || 'area ini';
    return normalizeVisitorLabel(name);
  }

  function normalizeVisitorLabel(name) {
    if (!name) return name;
    // Collapse duplicate prefixes: "Area Area Patung" → "Area Patung"
    name = name.replace(/\b(Area|Ruang|Gedung|Koridor|Lorong|Galeri|Monumen|Toko|Pintu|Balkon|Struktur)\s+\1\b/gi, '$1');
    // Collapse duplicate suffixes: "Maket Proyek Proyek" → "Maket Proyek"
    var words = name.split(/\s+/);
    var collapsed = [];
    for (var i = 0; i < words.length; i++) {
      if (i > 0 && normalize(words[i]) === normalize(words[i - 1])) continue;
      collapsed.push(words[i]);
    }
    return collapsed.join(' ');
  }

  function contentCapabilities(scene) {
    if (!scene) return null;
    return {
      hasIdentity: !!scene.rawName,
      hasDescription: !!scene.description,
      hasFacts: !!(scene.facts && scene.facts.length),
      hasHighlights: !!(scene.highlights && scene.highlights.length),
      hasFacilities: !!(scene.facilities && scene.facilities.length),
      hasVisibleObjects: !!(scene.visibleObjects && scene.visibleObjects.length),
      hasNavigation: graphExitIds(scene.id).length > 0
    };
  }

  function graphExitIds(sceneId) {
    var graph = buildSceneGraph();
    var seen = {};
    return (graph[sceneId] || []).filter(function (id) { return id !== sceneId; })
      .map(function (id) { return sceneById(id); })
      .filter(Boolean)
      .filter(function (scene) {
        var label = getVisitorSceneName(scene.id);
        if (seen[label]) return false;
        seen[label] = true;
        return true;
      });
  }

  function graphExitNames(sceneId) {
    return graphExitIds(sceneId).map(function (s) { return getVisitorSceneName(s.id); });
  }

  function recommendDestination(scene) {
    conversationState.lastRecommendedDestination = null;
    if (!scene) return null;
    var exits = graphExitIds(scene.id);
    if (!exits.length) return null;
    var target = exits[0];
    conversationState.lastRecommendedDestination = { sceneId: target.id, label: sceneDisplayName(target), sourceSceneId: scene.id, navigable: true };
    conversationState.lastRecommendedSceneId = target.id;
    conversationState.lastNavigableSceneId = target.id;
    var base = 'Rekomendasi berikutnya: lanjut ke ' + sceneDisplayName(target) + '.';
    if (exits.length === 1) return base + ' Dari area ini Anda dapat langsung menuju ke sana.';
    return base + ' Alternatif lain dari area ini: ' + exits.slice(1).map(sceneDisplayName).join(', ') + '.';
  }

  function recommendNext(scene) {
    return recommendDestination(scene);
  }

  function localRespond(query) {
    var text = normalize(query);
    var activeId = currentSceneId();
    var active = sceneById(activeId);

    var contextual = resolveContextualIntent(query, active);
    if (contextual) return contextual;

    var skinAction = skinActionFromQuery(query);
    if (skinAction) return { reply: skinAction.reply, intent: 'skin_action', confidence: 0.95, action: skinAction.action };

    if (/\b(video|hotspot|konten).{0,20}360|\b360\b.{0,20}(apa saja|apa aja|tersedia|ada|video|hotspot)/.test(text)) {
      var currentRelated = (data.video360 || []).filter(function (item) {
        return item.scene === activeId;
      });
      var allItems = (data.video360 || []).slice();
      var ordered = currentRelated.concat(allItems.filter(function (item) {
        return currentRelated.indexOf(item) === -1;
      }));
      if (!ordered.length) {
        return { reply: 'Belum ada video 360 terdaftar di data tur.', intent: 'video360', confidence: 0.8 };
      }
      var firstVideo = ordered[0];
      var firstScene = sceneById(firstVideo.scene) || { name: firstVideo.scene, id: firstVideo.scene };
      var wantsOpenVideo = /(buka|putar|play|jalankan|mulai).*(video|360)|^(buka|putar|play|jalankan|mulai)\s+(video|360)/.test(text);
      var wantsGoVideo = /(bawa|antar|arahkan|ke|menuju|go to|take me to).*(video|360)|^(video|360)\b/.test(text);
      var repeatedVideoReply = conversationState.lastIntent === 'video360' || /360 video experience/i.test(conversationState.lastReply || '');
      var singleReply = 'Ada satu video 360: ' + firstVideo.title + '. Lokasinya di panorama ' + sceneDisplayName(firstScene) + '. Pilih hotspot "360 Video Experience" untuk membukanya.';
      if (wantsOpenVideo) {
        singleReply = activeId === firstVideo.scene
          ? 'Video 360 siap dibuka di panorama ' + sceneDisplayName(firstScene) + '. Pilih hotspot "360 Video Experience" sekarang.'
          : 'Video 360 ada di panorama ' + sceneDisplayName(firstScene) + '. Pindah dulu ke sana, lalu tekan hotspot "360 Video Experience".';
      } else if (wantsGoVideo) {
        singleReply = 'Saya arahkan ke lokasi video 360. Tujuannya panorama ' + sceneDisplayName(firstScene) + ', lalu pilih hotspot "360 Video Experience".';
      } else if (repeatedVideoReply) {
        singleReply = 'Lokasi video 360 ada di ' + sceneDisplayName(firstScene) + '. Setelah tiba di sana, buka hotspot "360 Video Experience".';
      }
      return {
        reply: ordered.length === 1
          ? singleReply
          : 'Ada ' + ordered.length + ' video 360: ' + ordered.map(function (item) {
            var scene = sceneById(item.scene);
            return item.title + ', tersedia di panorama ' + (scene ? sceneDisplayName(scene) : item.scene);
          }).join('; ') + '.',
        intent: 'video360',
        confidence: 0.97,
        action: wantsGoVideo && firstScene.id ? 'go_to_scene' : 'none',
        scene: wantsGoVideo && firstScene.id ? firstScene.id : null,
        sources: ordered.map(function (item) {
          return { title: item.title, scene: item.scene, source: item.source, skinid: item.hotspotSkinId };
        })
      };
    }

    if (/^(halo|hai|hi|hello)\b/.test(text)) {
      return { reply: 'Halo. Saya pemandu virtual ' + data.name + '. Tanyakan lokasi atau fasilitas yang ingin dikunjungi.' };
    }

    // UNKNOWN_FACT (harga tiket, jam buka, etc.) → let Gemini handle

    if (/(di mana saya|saya di mana|posisi saya|sedang berada|where am i|current location)/.test(text)) {
      if (!active) return { reply: 'Area aktif belum dikenali dalam data tur.', intent: 'location', confidence: 0.5 };
      return {
        reply: 'Anda sekarang berada di ' + sceneDisplayName(active) + ' di tur ' + data.name + '.' + (active.description ? ' ' + active.description : ''),
        intent: 'location',
        confidence: 0.98,
        scene: active.id,
        evidenceTypes: ['structural'],
        capabilityFlags: contentCapabilities(active)
      };
    }

    // Area description → let API handle with provider composition
    // Do NOT intercept here — backend has knowledge + Groq for natural responses

    // Named entity/scene description → let API handle with knowledge resolution
    // Do NOT intercept here — backend resolves entities and composes responses

    if (/(rekomendasi berikutnya|habis ini ke mana|next spot|next area|lanjut ke mana|ke mana selanjutnya|next destination|recommendation)/.test(text)) {
      var recommendation = recommendNext(active);
      return {
        reply: recommendation || 'Belum ada rekomendasi lanjutan untuk area ini dalam data tur.',
        intent: 'recommend',
        confidence: recommendation ? 0.95 : 0.5,
        scene: active ? active.id : null,
        recommendation_target: conversationState.lastRecommendedDestination,
        evidenceTypes: ['graph']
      };
    }

    if (/(arahkan|antar|bawa|guide|bring|take me|go).*(dari|from).*(ke|menuju|to)|(?:dari|from).*(?:ke|menuju|to)/.test(text)) {
      var fromMatchText = text.match(/(?:dari|from)\s+(.+?)\s+(?:ke|menuju|to)\s+(.+)/);
      if (fromMatchText) {
        var relation = routeBetweenScenes(fromMatchText[1], fromMatchText[2]);
        if (relation) {
          return {
            reply: 'Rute dari ' + sceneDisplayName(relation.fromScene) + ' ke ' + sceneDisplayName(relation.toScene) + ': ' + relation.path.map(function (sceneId) {
              var scene = sceneById(sceneId);
              return scene ? sceneDisplayName(scene) : sceneId;
            }).join(' → ') + '. Saya mulai dari ' + sceneDisplayName(relation.fromScene) + '.',
            intent: 'route_relation',
            confidence: 0.95,
            action: 'start_route',
            route: relation.path
          };
        }
        return { reply: 'Relasi panorama dari titik awal ke tujuan belum ada di data rute saat ini.' };
      }
    }

    if (/(apa saja|daftar|lokasi tersedia|fasilitas tersedia)/.test(text)) {
      return { reply: 'Lokasi tersedia: ' + data.scenes.map(sceneDisplayName).join(', ') + '.' };
    }

    if (pendingClarification) {
      var chosen = pendingClarification.options.find(function (option) { return normalize(option) === text || text.indexOf(normalize(option)) !== -1; });
      var type = pendingClarification.type;
      pendingClarification = null;
      if (chosen) return localRespond(chosen);
    }

    var routeMatches = findRouteMatches(query);
    if (routeMatches.length > 1 && routeMatches[0].score === routeMatches[1].score) return clarify(routeMatches, 'route');
    var routeMatch = routeMatches[0];
    if (routeMatch && routeMatch.score > 0 && /(rute|jalur|saya|bawa|bersama|ingin|bring|take|go)/.test(text)) {
      var routeScenes = routeMatch.route.scenes.map(function (sceneId) {
        var scene = data.scenes.find(function (item) { return item.id === sceneId; });
        return scene ? sceneDisplayName(scene) : sceneId;
      });
      return {
        reply: 'Saya pilih ' + routeMatch.route.name + '. Urutan perjalanan: ' + routeScenes.join(' → ') + '. Saya mulai dari ' + routeScenes[0] + ', lalu Anda dapat melanjutkan ke titik berikutnya melalui hotspot panorama.',
        intent: 'route',
        confidence: 0.95,
        action: 'start_route',
        route: routeMatch.route.scenes
      };
    }

    if (/(kembali|awal|utama|mulai)/.test(text)) {
      var home = data.scenes[0];
      if (home) navigate(home);
      return { reply: 'Membawa Anda kembali ke tampilan awal tur ' + data.name + '.' };
    }

    var localHotspot = findHotspot(query, activeId);
    if (localHotspot && localHotspot.score > 0 && /\b(tunjukkan|sorot|buka|lihat|tampilkan|show|highlight|fokus|focus)\b/.test(text)) {
      focusHotspot(localHotspot.hotspot);
      return { reply: 'Saya menyorot ' + localHotspot.hotspot.title + '. Anda dapat memilih hotspot tersebut untuk melanjutkan.' };
    }

    if (/(ke sana|kesana|di sana|ke situ|di situ)/.test(text)) {
      // Prefer most recent navigable entity over stale recommendations
      var destId = null;
      if (conversationState.lastNavigableEntity) {
        // Find scene by last navigable entity name
        var navEntity = conversationState.lastNavigableEntity.toLowerCase();
        var navScene = data.scenes.find(function (s) {
          return (s.name || '').toLowerCase().indexOf(navEntity) !== -1 || (s.displayName || '').toLowerCase().indexOf(navEntity) !== -1 || (s.aliases || []).some(function (a) { return a.toLowerCase().indexOf(navEntity) !== -1; });
        });
        if (navScene) destId = navScene.id;
      }
      if (!destId) destId = (conversationState.lastRecommendedDestination && conversationState.lastRecommendedDestination.sceneId) || conversationState.lastRecommendedSceneId || conversationState.lastNavigableSceneId;
      var destScene = destId ? sceneById(destId) : null;
      if (destScene && destScene.id !== activeId) {
        navigate(destScene);
        return { reply: 'Saya bawa Anda ke ' + sceneDisplayName(destScene) + '.', intent: 'navigate', confidence: 0.96, action: 'none' };
      }
      if (destScene && destScene.id === activeId) return { reply: 'Anda sudah berada di ' + sceneDisplayName(destScene) + '.', intent: 'location', confidence: 0.96 };
      return { reply: 'Saya belum memiliki tujuan tersimpan untuk dibawa ke sana. Sebutkan area yang Anda tuju.', intent: 'navigate', confidence: 0.5 };
    }

    // Skip navigation for informational questions
    var isInfoQuery = /\b(pukul|jam|berapa|buka|tutup|harga|tiket|biaya|sejarah|kenapa|mengapa|apa itu|apa yang|ceritakan|jelaskan|deskripsikan|arti|makna|fungsi|menarik|unik|bagaimana|dimana|di mana|kapan|siapa|hubungan|kaitan|hubungannya|berkaitan)\b/.test(text);
    if (!isInfoQuery && isExplicitSceneNavigation(query)) {
      var navMatches = contextualSceneMatches(query);
      if (navMatches.length > 0) {
        navigate(navMatches[0].scene);
        return { reply: 'Membawa Anda ke ' + sceneDisplayName(navMatches[0].scene) + '.', intent: 'navigate', confidence: Math.min(1, 0.6 + navMatches[0].score / 20), action: 'go_to_scene', scene: navMatches[0].scene.id };
      }
      return { reply: 'Saya belum menemukan area tujuan yang cocok dalam data tur.', intent: 'navigate', confidence: 0.5 };
    }

    // ---- Semarang destination discovery ----
    // Back to Lawang Sewu context
    if (_pendingDestination && isBackToLawangSewu(query)) {
      _pendingDestination = null;
      _lastDestinationResults = [];
      if (active) {
        return { reply: 'Kembali ke ' + sceneDisplayName(active) + '. ' + sceneSummary(active), intent: 'area_info', confidence: 0.95, scene: active.id };
      }
    }

    // ---- Public service follow-up (client-side deterministic) ----
    var serviceFollowup = resolvePublicServiceFollowup(query);
    if (serviceFollowup) return serviceFollowup;

    // Check destination discovery — but SKIP for informational questions
    var isInfoQuery = /\b(kenapa|mengapa|apa itu|apa yang|ceritakan|jelaskan|deskripsikan|tentang|sejarah|arti|makna|fungsi|menarik|unik|istimewa|bagaimana|dimana|di mana|hubungan|kaitan|hubungannya|berkaitan|pukul|jam|berapa|buka|tutup|harga|tiket|siapa|kapan)\b/.test(normalize(query));
    if (!isInfoQuery) {
      var discoveryResult = resolveDestinationDiscovery(query);
      if (discoveryResult) return discoveryResult;
    }

    // ---- Explore menu entity resolution (client-side deterministic) ----
    var exploreEntity = resolveExploreEntity(query);
    if (exploreEntity && exploreEntity.description) {
      var exploreReply = exploreEntity.description;
      var navTarget = null;
      if (exploreEntity.sceneIds && exploreEntity.sceneIds.length) {
        navTarget = exploreEntity.sceneIds[0];
      }
      return {
        reply: exploreReply,
        intent: 'entity_description',
        confidence: 0.92,
        scene: active ? active.id : null,
        entity: { name: exploreEntity.title, id: exploreEntity.id, type: 'explore-place' },
        actions: [],
        navigationTarget: navTarget ? { sceneId: navTarget, placeId: exploreEntity.placeId || null, label: exploreEntity.title } : null
      };
    }

    // Unknown query → let API handle (never return local fallback for conversational input)
    return null;
  }

  function applyAction(result) {
    if (!result) return;
    var actions = result.actions && result.actions.length ? result.actions : [{ type: result.action, scene_id: result.scene, scene_ids: result.route, hotspot: result.hotspot, execution: 'autoExecute' }];
    var action = actions.find(function (item) { return item.execution === 'autoExecute'; });
    if (!action || !action.type) return;
    var skinAction = action.type === 'set_language' ? 'set_language_' + action.language : action.type;
    if (/^(set_language_|open_category|open_explore|open_menu|open_information|open_public_service|play_360)/.test(skinAction)) { runSkinAction(skinAction); return; }
    if (action.type === 'start_route') {
      var route = (data.routes || []).find(function (item) { return item.id === action.route_id; });
      var sceneIds = action.scene_ids || (route && route.scenes) || [];
      if (!sceneIds.length) return;
      conversationState.activeRoute = { type: route ? 'curated' : 'graph', id: action.route_id || null, sceneIds: sceneIds, currentStep: 0, startedAt: Date.now() };
      conversationState.lastRoute = sceneIds;
      var first = sceneById(sceneIds[0]); if (first) navigate(first);
      return;
    }
    if (action.type === 'continue_route' && conversationState.activeRoute) {
      var next = conversationState.activeRoute.sceneIds[conversationState.activeRoute.currentStep + 1]; if (next && sceneById(next)) navigate(sceneById(next));
      return;
    }
    if (action.type === 'go_to_scene' && sceneById(action.scene_id || action.scene)) { navigate(sceneById(action.scene_id || action.scene)); return; }
    if (action.type === 'open_virtual_tour' && action.destinationUrl) {
      if (validateTourUrl(action.destinationUrl)) {
        window.open(action.destinationUrl, '_blank', 'noopener,noreferrer');
      }
      return;
    }
    if (action.type === 'focus_hotspot' || action.type === 'show_hotspot_info') {
      var hotspot = data.hotspots.find(function (item) { return item.scene === currentSceneId() && normalize(item.title) === normalize(action.hotspot || ''); });
      if (hotspot) focusHotspot(hotspot);
    }
  }

  function resolveClarificationChoice(query) {
    if (!pendingClarification) return null;
    var text = normalize(query);
    var option = pendingClarification.options.find(function (item) { return normalize(item) === text || text.indexOf(normalize(item)) !== -1; });
    var clarType = pendingClarification.type;
    pendingClarification = null;
    if (!option) return null;
    if (clarType === 'scene' || clarType === 'route') {
      var navScene = contextualSceneMatches(option)[0];
      if (navScene && navScene.scene) {
        navigate(navScene.scene);
        conversationState.lastReferencedDestination = navScene.scene.id;
        conversationState.lastNavigableSceneId = navScene.scene.id;
        return { reply: 'Saya bawa Anda ke ' + sceneDisplayName(navScene.scene) + '. ' + sceneSummary(navScene.scene), intent: 'navigate', confidence: 0.95, action: 'go_to_scene', scene: navScene.scene.id };
      }
    }
    return localRespond(option);
  }

  function angularDistance(a, b) {
    return Math.abs(((a - b + 540) % 360) - 180);
  }

  function getAIViewContext() {
    var pan = window.pano && typeof window.pano.getPan === 'function' ? window.pano.getPan() : 0;
    var tilt = window.pano && typeof window.pano.getTilt === 'function' ? window.pano.getTilt() : 0;
    var fov = window.pano && typeof window.pano.getFov === 'function' ? window.pano.getFov() : 70;
    var visible = data.hotspots.filter(function (hotspot) { return hotspot.scene === currentSceneId(); }).map(function (hotspot) {
      return { title: hotspot.title, target: hotspot.target, pan: hotspot.pan, tilt: hotspot.tilt, angularDistance: angularDistance(pan, hotspot.pan) };
    }).filter(function (hotspot) { return hotspot.angularDistance <= fov / 2 + 8 && Math.abs(tilt - hotspot.tilt) <= fov / 2 + 8; }).sort(function (a, b) { return a.angularDistance - b.angularDistance; });
    return { pan: pan, tilt: tilt, fov: fov, visibleHotspots: visible, nearestViewHotspot: visible[0] || null };
  }

  function buildAITourContext() {
    var id = currentSceneId();
    var scene = sceneById(id);
    return { session: conversationState, scene: scene, view: getAIViewContext(), route: conversationState.activeRoute, visitor: conversationState.visitorProfile, nearby: data.hotspots.filter(function (hotspot) { return hotspot.scene === id; }), capabilities: { navigation: true, hotspotFocus: true, routes: true, video360: !!(data.video360 || []).length } };
  }

  function remoteRespond(query) {
    if (activeRequest) activeRequest.abort();
    activeRequest = new AbortController();
    var aiContext = buildAITourContext();
    
    var systemPrompt = 'Kamu adalah pemandu virtual resmi Lawang Sewu di Semarang. Menemani pengunjung menjelajahi Lawang Sewu. Hangat, santai, berpengetahuan. Gunakan bahasa Indonesia natural. Jawab berdasarkan pengetahuan tentang Lawang Sewu dan Semarang. Jangan mengarang fakta. Lokasi: ' + (currentScene() ? currentScene().name : 'Lawang Sewu');
    
    // Try PHP endpoint first, then client-side Gemini
    function tryPhpEndpoint() {
      return fetch(apiUrl, {
        method: 'POST',
        signal: activeRequest.signal,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          aiRequestId: 'web-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8),
          message: query,
          currentScene: currentSceneId(),
          history: history,
          conversationState: conversationState,
          viewContext: aiContext.view,
          tourContext: {
            scenes: data.scenes,
            hotspots: data.hotspots,
            routes: data.routes,
            video360: data.video360,
            ui: [
              { id: 'menu', label: 'Menu utama', function: 'membuka menu virtual tour' },
              { id: 'public_service', label: 'Layanan Publik', function: 'membuka portal layanan publik' },
              { id: 'information', label: 'Informasi', function: 'membuka panel informasi' },
              { id: 'explore', label: 'Jelajah', function: 'membuka daftar destinasi atau objek virtual tour' },
              { id: 'chatbot', label: 'Chatbot', function: 'membuka pemandu AI' },
              { id: 'category', label: 'Kategori', function: 'membuka Infrastruktur, Pariwisata, Budaya, Pertanian, Investasi, dan Religi' },
              { id: '360', label: '360°', function: 'menjalankan tampilan atau video 360 derajat' },
              { id: 'language', label: 'Bahasa', function: 'mengganti bahasa Indonesia, English, Dutch, Arabic, atau Chinese' },
              { id: 'map', label: 'Peta', function: 'menampilkan posisi panorama' },
              { id: 'navigation', label: 'Kompas dan zoom', function: 'mengatur arah serta pembesaran panorama' }
            ]
          }
        })
      }).then(function (response) {
        if (!response.ok) throw new Error('PHP endpoint returned ' + response.status);
        return response.json();
      });
    }

    function tryClientGemini() {
      return new Promise(function (resolve) {
        if (!window.aiClientRequest) {
          console.log('[tour-chat] aiClientRequest NOT FOUND');
          resolve(null);
          return;
        }
        console.log('[tour-chat] Calling client Gemini...');
        window.aiClientRequest(systemPrompt, query, function (err, text) {
          console.log('[tour-chat] Gemini callback:', err ? 'ERROR: ' + err : 'OK, text length: ' + (text ? text.length : 0));
          if (err || !text) { resolve(null); return; }
          try {
            var parsed = JSON.parse(text);
            console.log('[tour-chat] Gemini JSON:', JSON.stringify(parsed).substring(0, 200));
            resolve(parsed);
          } catch (e) {
            console.log('[tour-chat] Gemini plain text:', text.substring(0, 200));
            resolve({ reply: text, intent: 'GENERAL_CONVERSATION', confidence: 0.7, action: 'none' });
          }
        });
      });
    }

    // Chain: PHP → Gemini client → null
    return tryPhpEndpoint().catch(function (err) {
      console.log('[tour-chat] PHP failed:', err.message, '- trying client Gemini...');
      return tryClientGemini();
    }).then(function (result) {
      if (result) {
        // Normalize: Gemini might return "respons" (Indonesian) or "reply" (English)
        if (!result.reply && result.respons) {
          result.reply = result.respons;
        }
        // Also check for other common key variations
        if (!result.reply && result.answer) result.reply = result.answer;
        if (!result.reply && result.text) result.reply = result.text;
        console.log('[tour-chat] Got response from:', result.provider || 'client-gemini');
      } else {
        console.log('[tour-chat] No response from any provider');
      }
      return result;
    });
  }

  /* ================================================================
     PUBLIC SERVICE FOLLOW-UP (client-side deterministic)
     ================================================================ */

  function resolvePublicServiceFollowup(query) {
    if (!_lastPublicServiceResults || _lastPublicServiceResults.length === 0) return null;
    var text = normalize(query);

    // Number selection
    var numMap = { 'pertama': 0, '1': 0, 'kedua': 1, '2': 1, 'ketiga': 2, '3': 2, 'keempat': 3, '4': 3, 'kelima': 4, '5': 4 };
    var selected = null;
    var selectedIdx = -1;

    for (var word in numMap) {
      if (new RegExp('\\b' + word + '\\b').test(text)) {
        var idx = numMap[word];
        if (_lastPublicServiceResults[idx]) {
          selected = _lastPublicServiceResults[idx];
          selectedIdx = idx;
        }
        break;
      }
    }

    if (!selected && _lastPublicServiceResults.length === 1) {
      selected = _lastPublicServiceResults[0];
      selectedIdx = 0;
    }

    if (!selected) return null;

    // Determine what info they want
    var wantsPhone = /nomor|no\.?|telepon|telp\.?|hubungi|berapa|phone/i.test(text);
    var wantsAddress = /alamat|location|dimana|lokasi/i.test(text);
    var wantsWebsite = /website|situs|web|buka|link/i.test(text);
    var wantsDetail = !wantsPhone && !wantsAddress && !wantsWebsite;

    var reply = selected.name + ':';
    if (selected.phone && (wantsPhone || wantsDetail)) reply += '\n\ud83d\udcde Telepon: ' + selected.phone;
    if (selected.emergencyNumber && (wantsPhone || wantsDetail)) reply += '\n\ud83d\udea8 Nomor darurat: ' + selected.emergencyNumber;
    if (selected.address && (wantsAddress || wantsDetail)) reply += '\n\ud83d\udccd Alamat: ' + selected.address;
    if (selected.website && (wantsWebsite || wantsDetail)) reply += '\n\ud83c\udf10 Website: ' + selected.website;
    if (selected.contactUrl && selected.contactUrl !== selected.website && (wantsPhone || wantsDetail)) reply += '\n\ud83d\udcac WhatsApp: ' + selected.contactUrl;
    if (!selected.phone && !selected.website && !selected.address && !selected.contactUrl) {
      reply += '\nInformasi kontak tidak tersedia dalam data layanan saat ini.';
    }

    _lastPublicServiceResults = [];
    return {
      reply: reply,
      intent: 'public_service',
      confidence: 0.92,
      action: 'none'
    };
  }

  /* ================================================================
     DOM HELPERS
     ================================================================ */

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text) e.textContent = text;
    return e;
  }

  /* ================================================================
     TECHNICAL LABEL STRIPPING
     ================================================================ */

  var _technicalLabels = {};
  (function buildTechnicalLabels() {
    data.scenes.forEach(function (scene) {
      var reg = _sceneRegistry[scene.id];
      if (reg && scene.rawName !== reg.placeName) _technicalLabels[scene.rawName] = reg.placeName;
    });
  })();

  function sanitizeReply(reply) {
    if (!reply) return reply;
    var clean = reply;
    Object.keys(_technicalLabels).forEach(function (bad) {
      if (bad && clean.indexOf(bad) !== -1) clean = clean.split(bad).join(_technicalLabels[bad]);
    });
    return clean;
  }

  function currentScene() {
    var id = currentSceneId();
    return data.scenes.find(function (scene) { return scene.id === id; }) || data.scenes[0];
  }

  function quickPrompts() {
    var scene = currentScene();
    if (!scene) return ['Di mana saya?', 'Apa itu Lawang Sewu?', 'Rekomendasi berikutnya'];

    // Use answerability-aware suggestion builder
    var suggestions = buildContextualSuggestions({ sceneId: scene.id });
    if (suggestions.length > 0) {
      return suggestions.map(function (s) { return s.label; });
    }

    // Minimal fallback: only location + navigation (always answerable)
    var prompts = ['Di mana saya?'];
    var exits = graphExitIds(scene.id);
    if (exits.length > 0) prompts.push('Ke mana berikutnya?');
    return prompts.slice(0, 4);
  }

  /* ================================================================
     PROGRESSIVE REVEAL
     ================================================================ */

  function cancelProgressiveReveal() {
    if (!_streamState) return;
    clearInterval(_streamState.timerId);
    clearTimeout(_streamState.timerId);
    // finish immediately: set full text, remove caret
    var s = _streamState;
    s.el.textContent = s.fullText;
    removeCaret(s.el);
    _streamState = null;
  }

  function removeCaret(container) {
    var c = container.querySelector('.tour-chat__caret');
    if (c) c.remove();
  }

  function ensureCaret(container) {
    var existing = container.querySelector('.tour-chat__caret');
    if (existing) return existing;
    var caret = el('span', 'tour-chat__caret');
    container.appendChild(caret);
    return caret;
  }

  function startProgressiveReveal(text, element) {
    // Reduced motion: display instantly
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      element.textContent = text;
      _streamState = null;
      return;
    }
    // Tokenize: split on spaces, keeping punctuation attached
    var tokens = text.split(/(\s+)/);
    // Filter out empty tokens
    tokens = tokens.filter(function (t) { return t.length > 0; });

    var isShort = text.length < 200;
    var isMedium = text.length < 600;
    // Dynamic speed: short answers faster, long answers moderate
    var baseDelay = isShort ? 18 : (isMedium ? 12 : 7);
    var punctuationDelay = { ',': 20, ';': 20, '.': 35, '!': 35, '?': 35, '\n': 45 };

    var idx = 0;
    var displayed = '';
    ensureCaret(element);

    _streamState = {
      fullText: text,
      el: element,
      timerId: null,
      chunks: tokens
    };

    function tick() {
      if (idx >= tokens.length) {
        finishProgressiveReveal();
        return;
      }
      var token = tokens[idx];
      displayed += token;
      // Set text content up to current, then add caret
      element.textContent = displayed;
      ensureCaret(element);
      idx++;

      // Scroll to bottom as content streams in
      scrollCanvasToBottom();

      // Determine delay for next tick
      var trimmed = token.trim();
      var lastChar = trimmed.charAt(trimmed.length - 1);
      var delay = punctuationDelay[lastChar] || baseDelay;
      // After newlines, extra pause
      if (lastChar === '\n') delay = punctuationDelay['\n'];

      _streamState.timerId = setTimeout(tick, delay);
    }

    // Small initial delay before starting
    _streamState.timerId = setTimeout(tick, 80);
  }

  function finishProgressiveReveal() {
    if (!_streamState) return;
    var s = _streamState;
    clearInterval(s.timerId);
    clearTimeout(s.timerId);
    s.el.textContent = s.fullText;
    removeCaret(s.el);
    _streamState = null;
    scrollCanvasToBottom();
  }

  /* ================================================================
     MESSAGE RENDERING (new DOM structure)
     ================================================================ */

  function addUserMessage(container, text) {
    var msg = el('article', 'tour-chat__message tour-chat__message--user');
    msg.setAttribute('data-message-state', 'complete');
    var avatar = el('div', 'tour-chat__msg-avatar');
    var content = el('div', 'tour-chat__msg-content', text);
    msg.appendChild(avatar);
    msg.appendChild(content);
    container.appendChild(msg);
    scrollCanvasToBottom();
    return msg;
  }

  function addBotThinking(container) {
    var msg = el('article', 'tour-chat__message tour-chat__message--bot');
    msg.setAttribute('data-message-state', 'thinking');
    var avatar = el('div', 'tour-chat__msg-avatar');
    var avatarImg = el('img');
    avatarImg.src = 'assets/avatar.png';
    avatarImg.alt = 'Guide';
    avatarImg.loading = 'lazy';
    avatar.appendChild(avatarImg);
    var content = el('div', 'tour-chat__msg-content');
    var thinking = el('div', 'tour-chat__thinking');
    var dots = el('div', 'tour-chat__thinking-dots');
    dots.appendChild(el('span'));
    dots.appendChild(el('span'));
    dots.appendChild(el('span'));
    thinking.appendChild(dots);
    thinking.appendChild(el('span', 'tour-chat__thinking-label', 'Menyiapkan jawaban…'));
    content.appendChild(thinking);
    msg.appendChild(avatar);
    msg.appendChild(content);
    container.appendChild(msg);
    scrollCanvasToBottom();
    return msg;
  }

  function replaceThinkingWithStream(container, text, sources, options) {
    // Remove last child (thinking indicator)
    if (container.lastChild) container.removeChild(container.lastChild);

    var msg = el('article', 'tour-chat__message tour-chat__message--bot');
    msg.setAttribute('data-message-state', 'streaming');
    var avatar = el('div', 'tour-chat__msg-avatar');
    var avatarImg = el('img');
    avatarImg.src = 'assets/avatar.png';
    avatarImg.alt = 'Guide';
    avatarImg.loading = 'lazy';
    avatar.appendChild(avatarImg);
    var content = el('div', 'tour-chat__msg-content');
    msg.appendChild(avatar);
    msg.appendChild(content);
    container.appendChild(msg);

    // Start progressive reveal
    startProgressiveReveal(text, content);

    // When done, add sources/options and finalize state
    var checkDone = setInterval(function () {
      if (!_streamState || _streamState.el !== content) {
        clearInterval(checkDone);
        msg.setAttribute('data-message-state', 'complete');
        if (sources && sources.length) renderSources(msg, sources);
        if (options && options.length) renderInlineOptions(msg, options);
        scrollCanvasToBottom();
      }
    }, 200);

    scrollCanvasToBottom();
    return msg;
  }

  function renderSources(message, sources) {
    if (!sources || !sources.length) return;
    var sourceList = el('div', 'tour-chat__sources');
    sources.slice(0, 3).forEach(function (source) {
      var link = el('a', 'tour-chat__source', source.title || 'Knowledge base');
      link.href = source.url || '#';
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      sourceList.appendChild(link);
    });
    message.appendChild(sourceList);
  }

  function renderInlineOptions(message, options) {
    if (!options || !options.length) return;
    var wrap = el('div', 'tour-chat__suggestions--inline');
    options.forEach(function (label) {
      var btn = el('button', 'tour-chat__suggestion', label);
      btn.type = 'button';
      btn.addEventListener('click', function () { if (submitQuery) submitQuery(label); });
      wrap.appendChild(btn);
    });
    message.appendChild(wrap);
    scrollCanvasToBottom();
  }

  function scrollCanvasToBottom() {
    if (!_dom) return;
    var c = _dom.canvas;
    requestAnimationFrame(function () {
      c.scrollTop = c.scrollHeight;
    });
  }

  /* ================================================================
     MOUNT — new DOM structure
     ================================================================ */

  var _dom = null;

  function mount() {
    if (document.getElementById('tour-chat')) return;

    var root = el('aside', 'tour-chat tour-chat--closed');
    root.id = 'tour-chat';
    root.setAttribute('aria-label', 'Pemandu virtual ' + data.name);
    root.setAttribute('data-state', 'closed');

    // Backdrop
    var backdrop = el('div', 'tour-chat__backdrop');

    // Panel
    var panel = el('section', 'tour-chat__panel');
    panel.id = 'tour-chat-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-labelledby', 'tour-chat-title');

    // Header
    var header = el('header', 'tour-chat__header');
    var headerLeft = el('div', 'tour-chat__header-left');
    var headerAvatar = el('img', 'tour-chat__header-avatar');
    headerAvatar.src = 'assets/avatar.png';
    headerAvatar.alt = 'Pemandu Lawang Sewu';
    headerAvatar.loading = 'lazy';
    var identity = el('div', 'tour-chat__identity');
    var identityName = el('span', 'tour-chat__identity-name', 'Guide Lawang Sewu');
    var identitySub = el('span', 'tour-chat__identity-sub', 'Virtual Tour ' + data.name);
    identity.appendChild(identityName);
    identity.appendChild(identitySub);
    var placeLabel = el('span', 'tour-chat__place-label');
    headerLeft.appendChild(headerAvatar);
    headerLeft.appendChild(identity);
    headerLeft.appendChild(placeLabel);

    var headerActions = el('div', 'tour-chat__header-actions');
    var closeBtn = el('button', 'tour-chat__icon-btn');
    closeBtn.type = 'button';
    closeBtn.setAttribute('aria-label', 'Tutup pemandu AI');
    closeBtn.innerHTML = '&#x2715;';
    headerActions.appendChild(closeBtn);

    header.appendChild(headerLeft);
    header.appendChild(headerActions);

    // Canvas (scrollable area)
    var canvas = el('div', 'tour-chat__canvas');
    var canvasInner = el('div', 'tour-chat__canvas-inner');

    // Welcome state
    var welcome = el('div', 'tour-chat__welcome');
    var welcomeAvatar = el('img', 'tour-chat__welcome-avatar');
    welcomeAvatar.src = 'assets/avatar.png';
    welcomeAvatar.alt = 'Pemandu Lawang Sewu';
    welcomeAvatar.loading = 'lazy';
    var welcomeEyebrow = el('div', 'tour-chat__welcome-eyebrow', 'Guide Lawang Sewu');
    var welcomeTitle = el('div', 'tour-chat__welcome-title');
    welcomeTitle.innerHTML = 'Selamat Datang di <strong>' + (data.name || 'Lawang Sewu') + '</strong>';
    var welcomeSub = el('div', 'tour-chat__welcome-sub', 'Saya siap menemani Anda menjelajahi tempat ini. Anda bisa bertanya tentang area yang sedang dilihat, sejarah, objek menarik, atau meminta saya mengarahkan perjalanan.');
    var welcomeDivider = el('div', 'tour-chat__welcome-divider');
    welcome.appendChild(welcomeAvatar);
    welcome.appendChild(welcomeEyebrow);
    welcome.appendChild(welcomeTitle);
    welcome.appendChild(welcomeSub);
    welcome.appendChild(welcomeDivider);

    // Messages container
    var messages = el('div', 'tour-chat__messages');
    messages.setAttribute('aria-live', 'polite');

    canvasInner.appendChild(welcome);
    canvasInner.appendChild(messages);
    canvas.appendChild(canvasInner);

    // Suggestions bar
    var suggestionsBar = el('div', 'tour-chat__suggestions-bar');
    var suggestionsInner = el('div', 'tour-chat__suggestions-bar-inner');
    suggestionsBar.appendChild(suggestionsInner);

    // Composer
    var composerArea = el('div', 'tour-chat__composer-area');
    var composer = el('div', 'tour-chat__composer');
    var composerIcon = el('div', 'tour-chat__composer-icon');
    composerIcon.innerHTML = '&#x2728;';
    var textarea = el('textarea', 'tour-chat__input');
    textarea.rows = 1;
    textarea.placeholder = 'Tanya tentang lokasi, sejarah, objek, atau minta diarahkan…';
    textarea.setAttribute('aria-label', 'Pertanyaan untuk pemandu virtual');
    textarea.maxLength = 500;

    var sendBtn = el('button', 'tour-chat__send');
    sendBtn.type = 'submit';
    sendBtn.setAttribute('aria-label', 'Kirim');
    sendBtn.disabled = true;
    sendBtn.innerHTML = '&#x2191;'; // up arrow

    composer.appendChild(composerIcon);
    composer.appendChild(textarea);
    composer.appendChild(sendBtn);
    composerArea.appendChild(composer);

    // Assemble
    panel.appendChild(header);
    panel.appendChild(canvas);
    panel.appendChild(suggestionsBar);
    panel.appendChild(composerArea);
    root.appendChild(backdrop);
    root.appendChild(panel);
    document.body.appendChild(root);

    // Toggle button (floating entry)
    var toggle = el('button', 'tour-chat__toggle');
    toggle.type = 'button';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-controls', 'tour-chat-panel');
    var toggleIcon = el('span', 'tour-chat__toggle-icon');
    toggleIcon.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 1L14.5 8.5L22 11L14.5 13.5L12 21L9.5 13.5L2 11L9.5 8.5L12 1Z" fill="white"/><path d="M19 2L20 5L23 6L20 7L19 10L18 7L15 6L18 5L19 2Z" fill="white" opacity="0.7"/><path d="M5 15L6 17L8 18L6 19L5 21L4 19L2 18L4 17L5 15Z" fill="white" opacity="0.5"/></svg>';
    var toggleText = el('span', 'tour-chat__toggle-text', 'Tanya Guide Lawang Sewu');
    toggle.appendChild(toggleIcon);
    toggle.appendChild(toggleText);
    root.appendChild(toggle);

    _dom = {
      root: root,
      backdrop: backdrop,
      panel: panel,
      header: header,
      placeLabel: placeLabel,
      canvas: canvas,
      canvasInner: canvasInner,
      welcome: welcome,
      messages: messages,
      suggestionsBar: suggestionsBar,
      suggestionsInner: suggestionsInner,
      textarea: textarea,
      sendBtn: sendBtn,
      composer: composer,
      toggle: toggle,
      closeBtn: closeBtn
    };

    // --- Event wiring ---

    // Auto-grow textarea
    textarea.addEventListener('input', function () {
      textarea.style.height = 'auto';
      textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
      sendBtn.disabled = !textarea.value.trim();
    });

    // Send
    function doSend() {
      var val = textarea.value.trim();
      if (!val) return;
      submit(val);
    }
    sendBtn.addEventListener('click', doSend);

    // Form submit (Enter)
    textarea.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        doSend();
      }
      if (e.key === 'Escape') {
        closeOverlay();
      }
    });

    // Toggle open
    toggle.addEventListener('click', function () {
      if (root.classList.contains('tour-chat--closed')) openOverlay();
    });

    // Close
    closeBtn.addEventListener('click', closeOverlay);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !root.classList.contains('tour-chat--closed')) {
        closeOverlay();
      }
    });

    // Scene change listener
    var _lastSceneForContext = currentSceneId();
    setInterval(function () {
      var newScene = currentSceneId();
      if (newScene !== _lastSceneForContext) {
        _lastSceneForContext = newScene;
        refreshPlaceLabel();
        refreshSuggestions();
        // Add subtle scene divider if overlay is open and conversation exists
        if (!root.classList.contains('tour-chat--closed') && messages.children.length > 0) {
          addSceneDivider(messages, newScene);
        }
      }
    }, 1500);

    // Fullscreen handler — keep panel visible when player goes fullscreen
    function onFullscreenChange() {
      var fsEl = document.fullscreenElement || document.webkitFullscreenElement || document.mozFullScreenElement || document.msFullscreenElement;
      if (fsEl) {
        // Player went fullscreen — move panel inside fullscreen element so it stays visible
        if (fsEl !== root && !fsEl.contains(root)) {
          fsEl.appendChild(root);
        }
      } else {
        // Exited fullscreen — move panel back to body
        if (root.parentNode !== document.body) {
          document.body.appendChild(root);
        }
      }
    }
    document.addEventListener('fullscreenchange', onFullscreenChange);
    document.addEventListener('webkitfullscreenchange', onFullscreenChange);
    document.addEventListener('mozfullscreenchange', onFullscreenChange);
    document.addEventListener('MSFullscreenChange', onFullscreenChange);

    refreshPlaceLabel();
    refreshSuggestions();
  }

  /* ================================================================
     SCENE DIVIDER
     ================================================================ */

  function addSceneDivider(container, sceneId) {
    var scene = sceneById(sceneId);
    var name = scene ? sceneDisplayName(scene) : 'area baru';
    // Don't add duplicate dividers
    var lastChild = container.lastElementChild;
    if (lastChild && lastChild.classList.contains('tour-chat__scene-divider')) {
      lastChild.textContent = '📍 Sedang menjelajahi ' + name;
      return;
    }
    var divider = el('div', 'tour-chat__scene-divider', '📍 Sedang menjelajahi ' + name);
    container.appendChild(divider);
    scrollCanvasToBottom();
  }

  /* ================================================================
     OPEN / CLOSE with data-state animation
     ================================================================ */

  var _openTimer = null;

  function setPanelFocusable(panel, focusable) {
    var val = focusable ? '0' : '-1';
    var targets = panel.querySelectorAll('button, [href], input, select, textarea, [tabindex]');
    for (var i = 0; i < targets.length; i++) {
      targets[i].setAttribute('tabindex', val);
    }
  }

  function isTouchDevice() {
    return window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  }

  function openOverlay() {
    var d = _dom;
    if (!d) return;
    cancelProgressiveReveal();
    clearTimeout(_openTimer);

    d.root.classList.remove('tour-chat--closed');
    d.root.setAttribute('data-state', 'open');
    d.toggle.setAttribute('aria-expanded', 'true');
    setPanelFocusable(d.panel, true);

    // Avoid keyboard pop-up on touch devices
    if (!isTouchDevice()) {
      d.textarea.focus();
    }
  }

  function closeOverlay() {
    var d = _dom;
    if (!d) return;
    cancelProgressiveReveal();
    clearTimeout(_openTimer);

    d.root.classList.add('tour-chat--closed');
    d.root.setAttribute('data-state', 'closed');
    d.toggle.setAttribute('aria-expanded', 'false');
    setPanelFocusable(d.panel, false);
    d.toggle.focus();
  }

  /* ================================================================
     PLACE + SUGGESTIONS
     ================================================================ */

  function refreshPlaceLabel() {
    var d = _dom;
    if (!d) return;
    var scene = currentScene();
    d.placeLabel.textContent = scene ? ('\uD83D\uDCCD ' + sceneDisplayName(scene)) : '';
  }

  function refreshSuggestions() {
    var d = _dom;
    if (!d) return;
    // Transition out old chips
    d.suggestionsBar.classList.add('transitioning');
    setTimeout(function () {
      d.suggestionsInner.innerHTML = '';
      d.suggestionsBar.classList.remove('transitioning');

      // Get answerability-aware suggestions
      var scene = currentScene();
      var suggestions = scene ? buildContextualSuggestions({ sceneId: scene.id }) : [];

      suggestions.forEach(function (suggestion) {
        var btn = el('button', 'tour-chat__suggestion', suggestion.label);
        btn.type = 'button';
        btn.setAttribute('data-evidence', suggestion.evidenceType);
        btn.setAttribute('data-confidence', String(suggestion.confidence));
        btn.addEventListener('click', function () { submit(suggestion.prompt); });
        d.suggestionsInner.appendChild(btn);
      });
    }, 170);
  }

  /* ================================================================
     SUBMIT (presentation layer only — AI logic untouched)
     ================================================================ */

  function submit(value) {
    var query = value.trim();
    var now = Date.now();
    if (!query || (normalize(query) === normalize(lastSubmitted.text) && now - lastSubmitted.at < 900)) return;
    lastSubmitted = { text: query, at: now };

    var d = _dom;

    // If overlay not open, open it
    if (d && d.root.classList.contains('tour-chat--closed')) openOverlay();

    // Cancel any in-progress stream
    cancelProgressiveReveal();

    // Hide welcome
    if (d) d.welcome.classList.add('hidden');

    // Add user bubble
    if (d) addUserMessage(d.messages, query);

    // Clear input
    if (d) {
      d.textarea.value = '';
      d.textarea.style.height = 'auto';
      d.sendBtn.disabled = true;
    }

    // Show thinking
    var thinkingEl = d ? addBotThinking(d.messages) : null;

    var clarified = resolveClarificationChoice(query);
    var local = clarified ? clarified : localRespond(query);
    var deterministic = !!local && !!local.intent;
    var resolved = deterministic
      ? Promise.resolve(local)
      : remoteRespond(query).then(function (result) {
        if (!result || !result.reply || result.reply === 'Layanan AI tidak terjangkau.' || result.reply === 'Respons AI tidak valid.') return null;
        return result;
      });
    resolved.then(function (result) {
      if (!result || !result.reply) {
        // API returned empty — show connection error
        result = { reply: 'Maaf, Guide Lawang Sewu tidak dapat memproses pertanyaan ini. Silakan coba lagi.', intent: 'error', action: 'none' };
      }
      if (window.AI_DEBUG) {
        result._debug = {
          intent: result.intent || null,
          subjectType: result._debug ? result._debug.subjectType : null,
          subjectId: result._debug ? result._debug.subjectId : null,
          currentSceneId: currentSceneId(),
          currentRawSceneName: result._debug ? result._debug.currentRawSceneName : null,
          currentPlaceId: result._debug ? result._debug.currentPlaceId : null,
          currentPlaceName: result._debug ? result._debug.currentPlaceName : null,
          resolvedEntityId: result._debug ? result._debug.resolvedEntityId : null,
          knowledgeIds: result._debug ? result._debug.knowledgeIds : null,
          retrievalScores: result._debug ? result._debug.retrievalScores : null,
          navigationIntent: result._debug ? result._debug.navigationIntent : null,
          responseStrategy: result._debug ? result._debug.responseStrategy : null,
          providerUsed: result.provider || (deterministic ? 'local' : 'none'),
          fallbackUsed: deterministic ? false : !result.provider,
          actionTarget: result.action || null,
          catalogCategory: result.intent === 'DESTINATION_DISCOVERY' ? (detectDiscoveryCategory(query) || null) : null,
          destinationIds: result.intent === 'DESTINATION_DISCOVERY' ? _lastDestinationResults.map(function (d) { return d.id; }) : null,
          pendingDestination: _pendingDestination || null
        };
      }
      if (result.needs_clarification && result.clarification_options && result.clarification_options.length) pendingClarification = { type: 'remote', options: result.clarification_options };
      result.reply = sanitizeReply(result.reply);

      // Replace thinking with progressive stream
      if (d) {
        replaceThinkingWithStream(d.messages, result.reply, result.sources, result.clarification_options);
      }

      applyAction(result);
      updateConversationState(result, query);
      refreshPlaceLabel();
      refreshSuggestions();
      remember('user', query);
      remember('assistant', result.reply);
    }).catch(function () {
      // API failed — show connection error, NOT local fallback for conversational queries
      var errorReply = 'Maaf, Guide Lawang Sewu sedang mengalami gangguan koneksi. Silakan coba lagi dalam beberapa saat.';
      if (d) {
        if (d.messages.lastChild) d.messages.removeChild(d.messages.lastChild);
        replaceThinkingWithStream(d.messages, errorReply);
      }
      remember('assistant', errorReply);
      updateConversationState(fallback, query);
    });
  }
  submitQuery = submit;

  /* ================================================================
     BOOT
     ================================================================ */

  function boot() {
    if (!window.pano) {
      window.setTimeout(boot, 100);
      return;
    }
    mount();
    // Load answerability data in parallel
    loadSemarangCatalog();
    loadSceneIdentity();
    loadKnowledge();
    loadExploreMenu();
    loadProviderHealth();

    // Show toggle button when skin's bottom menu becomes visible
    function showToggleWhenReady() {
      var toggle = document.querySelector('.tour-chat__toggle');
      if (!toggle) return;
      // Check if bottom menu or mobile bottom menu is visible
      var bottomMenu = document.querySelector('.ggskin_container.bottom-menu') || document.querySelector('[class*="bottom_menu"]');
      if (bottomMenu && parseFloat(getComputedStyle(bottomMenu).opacity) > 0.5) {
        toggle.classList.add('tour-chat__toggle--visible');
        return;
      }
      // Also check for VB variable controlling bottom visibility
      if (window.pano && window.pano.skinObj) {
        var vb = window.pano.skinObj;
        if (vb._bottom && parseFloat(getComputedStyle(vb._bottom).opacity) > 0.5) {
          toggle.classList.add('tour-chat__toggle--visible');
          return;
        }
      }
      // Retry until skin is ready (max 10 seconds)
      setTimeout(showToggleWhenReady, 300);
    }
    // Start checking after a short delay to let skin initialize
    setTimeout(showToggleWhenReady, 1500);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
}());
