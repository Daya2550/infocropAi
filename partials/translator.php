<?php
// partials/translator.php — Premium Indian Language Picker
?>
<div id="lang-fab" onclick="toggleLangPanel()" title="Change Language / भाषा बदलें" aria-label="Language selector">
  <span class="lang-fab-icon">🌐</span>
  <span class="lang-fab-text" id="langFabText">भाषा</span>
  <span class="lang-fab-arrow" id="langFabArrow">▲</span>
</div>

<div id="lang-panel" aria-label="Select Indian Language">
  <div class="lang-panel-header">
    <div class="lang-panel-title">
      <span>🇮🇳</span> भाषा चुनें — Choose Language
    </div>
    <button class="lang-panel-close" onclick="closeLangPanel()" aria-label="Close">✕</button>
  </div>
  <div class="lang-panel-subtitle">InfoCrop AI supports all major Indian languages</div>

  <div class="lang-grid" id="langGrid">
    <button class="lang-card lang-active" data-lang="en" data-name="English" onclick="setLang('en','English',this)">
      <span class="lang-native">English</span><span class="lang-label">English</span>
    </button>
    <button class="lang-card" data-lang="hi" data-name="Hindi" onclick="setLang('hi','Hindi',this)">
      <span class="lang-native">हिंदी</span><span class="lang-label">Hindi</span>
    </button>
    <button class="lang-card" data-lang="mr" data-name="Marathi" onclick="setLang('mr','Marathi',this)">
      <span class="lang-native">मराठी</span><span class="lang-label">Marathi</span>
    </button>
    <button class="lang-card" data-lang="te" data-name="Telugu" onclick="setLang('te','Telugu',this)">
      <span class="lang-native">తెలుగు</span><span class="lang-label">Telugu</span>
    </button>
    <button class="lang-card" data-lang="ta" data-name="Tamil" onclick="setLang('ta','Tamil',this)">
      <span class="lang-native">தமிழ்</span><span class="lang-label">Tamil</span>
    </button>
    <button class="lang-card" data-lang="kn" data-name="Kannada" onclick="setLang('kn','Kannada',this)">
      <span class="lang-native">ಕನ್ನಡ</span><span class="lang-label">Kannada</span>
    </button>
    <button class="lang-card" data-lang="ml" data-name="Malayalam" onclick="setLang('ml','Malayalam',this)">
      <span class="lang-native">മലയാളം</span><span class="lang-label">Malayalam</span>
    </button>
    <button class="lang-card" data-lang="gu" data-name="Gujarati" onclick="setLang('gu','Gujarati',this)">
      <span class="lang-native">ગુજરાતી</span><span class="lang-label">Gujarati</span>
    </button>
    <button class="lang-card" data-lang="pa" data-name="Punjabi" onclick="setLang('pa','Punjabi',this)">
      <span class="lang-native">ਪੰਜਾਬੀ</span><span class="lang-label">Punjabi</span>
    </button>
    <button class="lang-card" data-lang="bn" data-name="Bengali" onclick="setLang('bn','Bengali',this)">
      <span class="lang-native">বাংলা</span><span class="lang-label">Bengali</span>
    </button>
    <button class="lang-card" data-lang="or" data-name="Odia" onclick="setLang('or','Odia',this)">
      <span class="lang-native">ଓଡ଼ିଆ</span><span class="lang-label">Odia</span>
    </button>
    <button class="lang-card" data-lang="as" data-name="Assamese" onclick="setLang('as','Assamese',this)">
      <span class="lang-native">অসমীয়া</span><span class="lang-label">Assamese</span>
    </button>
    <button class="lang-card" data-lang="ur" data-name="Urdu" onclick="setLang('ur','Urdu',this)">
      <span class="lang-native">اردو</span><span class="lang-label">Urdu</span>
    </button>
    <button class="lang-card" data-lang="sa" data-name="Sanskrit" onclick="setLang('sa','Sanskrit',this)">
      <span class="lang-native">संस्कृत</span><span class="lang-label">Sanskrit</span>
    </button>
  </div>

  <div class="lang-panel-footer">
    Powered by Google Translate · 🇮🇳 Made for Indian Farmers
  </div>
</div>

<div id="google_translate_element" style="display:none;position:absolute;left:-9999px;top:-9999px;"></div>
<div id="lang-toast"></div>
<div id="lang-backdrop" onclick="closeLangPanel()"></div>

<style>
/* ── Suppress Google Translate top bar ─────────────────────── */
body { top: 0 !important; }
.goog-te-banner-frame, #goog-gt-tt, .goog-te-balloon-frame, .VIpgJd-yAWNEb-hvhgNd { display: none !important; }
.goog-te-gadget { font-size: 0 !important; }
.goog-logo-link, .goog-te-gadget > span { display: none !important; }
iframe.skiptranslate { display: none !important; }

/* ── Floating Action Button ─────────────────────────────────── */
#lang-fab {
  position: fixed; bottom: 24px; right: 24px; z-index: 9998;
  display: flex; align-items: center; gap: 7px;
  background: linear-gradient(135deg, #1b5e20, #2e7d32); color: #fff;
  padding: 12px 20px; border-radius: 50px; cursor: pointer;
  box-shadow: 0 6px 24px rgba(27,94,32,0.45); font-family: 'Inter', sans-serif;
  font-size: 0.88rem; font-weight: 700; transition: all 0.25s cubic-bezier(.4,0,.2,1);
  user-select: none; letter-spacing: 0.3px;
}
#lang-fab:hover {
  transform: translateY(-3px); box-shadow: 0 10px 32px rgba(27,94,32,0.55);
  background: linear-gradient(135deg, #2e7d32, #43a047);
}
#lang-fab.fab-open { background: linear-gradient(135deg, #0d47a1, #1565c0); box-shadow: 0 6px 24px rgba(13,71,161,0.45); }
.lang-fab-icon { font-size: 1.1rem; }
.lang-fab-text { font-size: 0.88rem; }
.lang-fab-arrow { font-size: 0.65rem; opacity: 0.8; transition: transform 0.25s ease; }
#lang-fab.fab-open .lang-fab-arrow { transform: rotate(180deg); }

/* ── Language Panel ─────────────────────────────────────────── */
#lang-panel {
  position: fixed; bottom: 90px; right: 24px; z-index: 9997;
  width: 380px; max-width: calc(100vw - 32px);
  background: #ffffff; border-radius: 20px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.18), 0 4px 20px rgba(0,0,0,0.08);
  border: 1px solid rgba(46,125,50,0.12); font-family: 'Inter', sans-serif;
  overflow: hidden; transform: translateY(20px) scale(0.95);
  opacity: 0; pointer-events: none; transition: all 0.28s cubic-bezier(.34,1.56,.64,1);
}
#lang-panel.panel-open { transform: translateY(0) scale(1); opacity: 1; pointer-events: all; }
.lang-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px 10px; background: linear-gradient(135deg, #1b5e20, #2e7d32); color: #fff; }
.lang-panel-title { font-size: 1rem; font-weight: 800; display: flex; align-items: center; gap: 8px; letter-spacing: -0.2px; }
.lang-panel-close { background: rgba(255,255,255,0.15); border: none; color: #fff; cursor: pointer; width: 28px; height: 28px; border-radius: 50%; font-size: 0.8rem; display: flex; align-items: center; justify-content: center; transition: background 0.2s; }
.lang-panel-close:hover { background: rgba(255,255,255,0.3); }
.lang-panel-subtitle { background: #f0f7f0; text-align: center; font-size: 0.73rem; color: #4a7a4e; padding: 7px 16px; font-weight: 600; border-bottom: 1px solid #dceedd; letter-spacing: 0.2px; }

.lang-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding: 16px; max-height: 300px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #c8e6c9 transparent; }
.lang-grid::-webkit-scrollbar { width: 4px; }
.lang-grid::-webkit-scrollbar-thumb { background: #c8e6c9; border-radius: 4px; }

.lang-card { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 10px 6px; border-radius: 12px; border: 1.5px solid #e8f5e9; background: #fafffe; cursor: pointer; transition: all 0.18s ease; text-align: center; min-height: 64px; outline: none; font-family: 'Inter', sans-serif; position: relative; }
.lang-card:hover { border-color: #43a047; background: #f0faf0; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(46,125,50,0.15); }
.lang-card.lang-active { border-color: #2e7d32; background: linear-gradient(135deg, #e8f5e9, #f0faf0); box-shadow: 0 0 0 2px rgba(46,125,50,0.25); }
.lang-native { font-size: 1.05rem; font-weight: 700; color: #1b5e20; line-height: 1.2; display: block; }
.lang-label { font-size: 0.6rem; font-weight: 600; color: #7a9e7e; text-transform: uppercase; letter-spacing: 0.5px; display: block; }
.lang-card.lang-active .lang-label { color: #2e7d32; font-weight: 700; }
.lang-card.lang-active::after { content: '✓'; position: absolute; top: 4px; right: 6px; font-size: 0.65rem; color: #2e7d32; font-weight: 800; }

.lang-panel-footer { padding: 10px 16px; text-align: center; font-size: 0.68rem; color: #94a3b8; background: #f8fafa; border-top: 1px solid #eef2ee; font-weight: 500; }

#lang-backdrop { display: none; position: fixed; inset: 0; z-index: 9996; background: rgba(0,0,0,0.25); backdrop-filter: blur(2px); }
#lang-backdrop.open { display: block; }

#lang-toast { position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%) translateY(20px); background: #1b5e20; color: #fff; font-family: 'Inter', sans-serif; font-size: 0.82rem; font-weight: 600; padding: 10px 22px; border-radius: 50px; box-shadow: 0 6px 20px rgba(0,0,0,0.2); opacity: 0; transition: all 0.3s ease; z-index: 10000; pointer-events: none; white-space: nowrap; }
#lang-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

@media (max-width: 480px) {
  #lang-panel { width: calc(100vw - 16px); right: 8px; bottom: 80px; }
  #lang-fab { bottom: 16px; right: 16px; padding: 10px 16px; }
  .lang-grid { grid-template-columns: repeat(3, 1fr); }
}
</style>

<script>
// ── Language Panel Controls ───────────────────────────────────
(function() {
  const PANEL   = document.getElementById('lang-panel');
  const FAB     = document.getElementById('lang-fab');
  const BACKDROP = document.getElementById('lang-backdrop');
  const FAB_TEXT = document.getElementById('langFabText');

  window.toggleLangPanel = function() { PANEL.classList.contains('panel-open') ? closeLangPanel() : openLangPanel(); }
  window.openLangPanel   = function() { PANEL.classList.add('panel-open'); FAB.classList.add('fab-open'); BACKDROP.classList.add('open'); }
  window.closeLangPanel  = function() { PANEL.classList.remove('panel-open'); FAB.classList.remove('fab-open'); BACKDROP.classList.remove('open'); }

  // ── Clear ALL googtrans cookies (all domain variants) ────────
  function clearAllGoogTransCookies() {
    var host = location.hostname;
    var expiry = '=;expires=Thu, 01 Jan 1970 00:00:01 GMT;path=/';
    // Clear for bare hostname
    document.cookie = 'googtrans' + expiry;
    document.cookie = 'googtrans' + expiry + ';domain=' + host;
    // Clear for dotted domain (.host.com)
    document.cookie = 'googtrans' + expiry + ';domain=.' + host;
    // For subdomains like x.free.nf, also clear parent domain
    var parts = host.split('.');
    if (parts.length > 2) {
      var parent = parts.slice(-2).join('.');
      document.cookie = 'googtrans' + expiry + ';domain=.' + parent;
      document.cookie = 'googtrans' + expiry + ';domain=' + parent;
    }
  }

  window.setLang = function(langCode, langName, btn) {
    document.querySelectorAll('.lang-card').forEach(c => c.classList.remove('lang-active'));
    btn.classList.add('lang-active');
    const nativeText = btn.querySelector('.lang-native').textContent;
    FAB_TEXT.textContent = nativeText;
    closeLangPanel();
    showToast('🌐 ' + langName + ' (' + nativeText + ') selected');

    // Always clear existing cookies first (fixes switching between non-English langs)
    clearAllGoogTransCookies();

    if (langCode === 'en') {
      // For English: just clear cookies and reload to restore original page
      location.reload();
      return;
    }

    // Set new language cookie on all domain variants
    var host = location.hostname;
    var val = '/en/' + langCode;
    document.cookie = 'googtrans=' + val + ';path=/';
    document.cookie = 'googtrans=' + val + ';path=/;domain=' + host;
    document.cookie = 'googtrans=' + val + ';path=/;domain=.' + host;
    var parts = host.split('.');
    if (parts.length > 2) {
      var parent = parts.slice(-2).join('.');
      document.cookie = 'googtrans=' + val + ';path=/;domain=.' + parent;
    }

    // Reload — most reliable method on hosted environments
    location.reload();
  }

  function showToast(msg) {
    const t = document.getElementById('lang-toast');
    t.textContent = msg; t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2800);
  }

  // ── Restore active button on page load from cookie ──────────
  (function restoreActiveLang() {
    var match = document.cookie.match(/googtrans=\/en\/([a-z]{2})/);
    if (match) {
      var code = match[1];
      document.querySelectorAll('.lang-card').forEach(function(c) {
        c.classList.remove('lang-active');
        if (c.getAttribute('data-lang') === code) {
          c.classList.add('lang-active');
          FAB_TEXT.textContent = c.querySelector('.lang-native').textContent;
        }
      });
    }
  })();

  window.googleTranslateElementInit = function() {
    new google.translate.TranslateElement({
      pageLanguage: 'en',
      includedLanguages: 'hi,mr,te,ta,kn,ml,gu,pa,bn,or,as,ur,sa',
      layout: google.translate.TranslateElement.InlineLayout.SIMPLE,
      autoDisplay: false
    }, 'google_translate_element');
  }
})();
</script>
<script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
