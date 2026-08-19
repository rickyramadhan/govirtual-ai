/**
 * ai-client.js — Client-side Gemini API caller.
 * Bypasses PHP entirely. Calls Gemini directly from browser.
 * API key is for free tier only — limited risk.
 *
 * INSTRUCTIONS: Copy this file to ai-client.js and replace YOUR_GEMINI_API_KEY with your actual key.
 */
(function () {
  'use strict';

  var GEMINI_API_KEY = 'YOUR_GEMINI_API_KEY';
  var GEMINI_MODEL = 'gemini-3.5-flash-lite';
  var GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/' + GEMINI_MODEL + ':generateContent?key=' + GEMINI_API_KEY;

  window.aiClientRequest = function (systemPrompt, userMessage, callback) {
    var fullPrompt = systemPrompt + '\n\nIMPORTANT RULES:\n1. Always reply in JSON format: {"reply":"your answer here","intent":"ENTITY_DESCRIPTION","confidence":0.9}.\n2. Use "reply" as the key (NOT "respons" or "answer").\n3. Do NOT start with "Halo!" or "Selamat datang" on follow-up questions. Only greet on the very first message.\n4. Jump straight to the answer. No unnecessary greetings or filler words.\n5. Be concise and direct.';
    
    var payload = {
      systemInstruction: { parts: [{ text: fullPrompt }] },
      contents: [{ role: 'user', parts: [{ text: userMessage }] }],
      generationConfig: {
        temperature: 0.3,
        maxOutputTokens: 600,
        responseMimeType: 'application/json'
      }
    };

    fetch(GEMINI_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
    .then(function (response) {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    })
    .then(function (data) {
      var text = (data.candidates && data.candidates[0] && data.candidates[0].content && data.candidates[0].content.parts && data.candidates[0].content.parts[0] && data.candidates[0].content.parts[0].text) || '';
      callback(null, text);
    })
    .catch(function (err) {
      callback(err.message || 'Gemini request failed');
    });
  };
})();
