/* MODASR — mini-app account hub.
 * Injected via app/index.php; independent of the React bundle.
 * On the account tab: floating 🔗 button opens a bottom sheet with
 * profile (name/phone), persistent login link, Google binding and
 * cross-platform linking. Also patches the bundle's profile name/avatar
 * when the messenger webview provides no real name (splus/rubika).
 * Talks to /api/miniapp with the session Bearer token (captured from the
 * bundle's own API calls via an XHR header hook). */
(function () {
  "use strict";

  var authHeader = null;
  var origSetHeader = XMLHttpRequest.prototype.setRequestHeader;
  XMLHttpRequest.prototype.setRequestHeader = function (name, value) {
    try {
      if (String(name).toLowerCase() === "authorization") authHeader = value;
    } catch (e) {}
    return origSetHeader.apply(this, arguments);
  };

  var T = {
    title: "⚙️ حساب و اتصال‌ها",
    subtitle: "پروفایل، ورود و پلتفرم‌های متصل — همه یکجا",
    currentPlatform: "پلتفرم فعلی",
    primary: "حساب اصلی",
    profile: "👤 پروفایل",
    phone: "شماره تماس",
    phoneEmpty: "ثبت نشده",
    phoneAdd: "ثبت شماره",
    phoneSaved: "✅ شماره ثبت شد",
    phoneInvalid: "❌ شماره معتبر نیست (مثلاً 09123456789)",
    permLink: "🔗 لینک دائمی ورود",
    permLinkHint: "با این لینک از هر مرورگری وارد می‌شوید. مثل رمز عبور است — به کسی ندهید.",
    copy: "کپی",
    copied: "✅ کپی شد",
    regenerate: "بازنشانی لینک",
    regenerated: "✅ لینک جدید ساخته شد — لینک قبلی از کار افتاد",
    confirmRegen: "لینک دائمی فعلی از کار می‌افتد و لینک جدید ساخته می‌شود. مطمئنید؟",
    google: "🇬 ورود با گوگل",
    googleLinked: "متصل:",
    googleLink: "اتصال گوگل",
    googleUnlink: "قطع گوگل",
    googleSoon: "به‌زودی فعال می‌شود",
    linkedTitle: "🔗 پلتفرم‌های متصل",
    noLinks: "هنوز پلتفرمی متصل نشده است",
    getCode: "➕ دریافت کد اتصال",
    enterCode: "📥 وارد کردن کد اتصال",
    unlink: "قطع اتصال",
    codeIssued: "کد اتصال شما:",
    codeHint: "تا ۵ دقیقه و فقط یک‌بار معتبر است. در بات پلتفرم دیگر این دستور را بفرستید:",
    codeHint2: "یا از دکمه‌ی «اتصال پلتفرم‌ها» در همان بات، گزینه‌ی «وارد کردن کد» را بزنید.",
    askCode: "کد ۶ رقمی را که در بات پلتفرم دیگر گرفتید وارد کنید:",
    submit: "اتصال",
    cancel: "انصراف",
    linkedOk: "✅ حساب‌ها متصل شدند",
    unlinkedOk: "✅ اتصال قطع شد",
    confirmUnlink: "قطع اتصال این پلتفرم؟ موجودی منتقل‌شده برنمی‌گردد.",
    loading: "…",
    errors: {
      invalid_code: "❌ کد نامعتبر یا قبلاً استفاده شده است",
      expired: "❌ کد منقضی شده است",
      rate_limited: "❌ تلاش‌های ناموفق زیاد بود؛ چند دقیقه دیگر تلاش کنید",
      self_link: "❌ نمی‌توانید حساب را به خودش متصل کنید",
      agent_blocked: "❌ حساب‌های نمایندگی قابل اتصال نیستند",
      already_linked: "❌ این حساب قبلاً متصل شده است",
      primary_is_linked: "❌ کد را از حساب اصلی بگیرید",
      has_own_links: "❌ ابتدا اتصال‌های فعلی این حساب را قطع کنید",
      too_many_codes: "❌ تعداد کدهای فعال زیاد است؛ کمی صبر کنید",
      is_secondary: "❌ کد اتصال را از حساب اصلی دریافت کنید",
      unlink_failed: "❌ قطع اتصال انجام نشد",
      invalid_phone: "❌ شماره معتبر نیست (مثلاً 09123456789)",
      google_not_configured: "ورود با گوگل هنوز فعال نشده است",
      error: "❌ خطای موقت؛ دوباره تلاش کنید"
    }
  };

  function api(action, opts) {
    opts = opts || {};
    var method = opts.method || "GET";
    var url = "/api/miniapp?actions=" + encodeURIComponent(action) + "&user_id=0";
    var headers = { "Content-Type": "application/json" };
    if (authHeader) headers["Authorization"] = authHeader;
    return fetch(url, {
      method: method,
      headers: headers,
      body: method === "POST" ? JSON.stringify(opts.body || {}) : undefined
    }).then(function (r) { return r.json(); });
  }

  function errText(code) { return T.errors[code] || T.errors.error; }

  var css = ""
    + "#mdx-fab{position:fixed;bottom:96px;left:16px;z-index:9999;width:52px;height:52px;border-radius:50%;"
    + "background:linear-gradient(135deg,#35bfae,#7c5cff);color:#fff;font-size:22px;border:none;cursor:pointer;"
    + "box-shadow:0 4px 16px rgba(53,191,174,.45);display:none;align-items:center;justify-content:center;}"
    + "#mdx-fab.mdx-show{display:flex;}"
    + "#mdx-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10000;display:none;}"
    + "#mdx-overlay.mdx-show{display:block;}"
    + "#mdx-sheet{position:fixed;left:0;right:0;bottom:0;z-index:10001;background:#1c1c22;color:#eee;"
    + "border-radius:20px 20px 0 0;padding:20px 18px 30px;direction:rtl;font-family:inherit;"
    + "transform:translateY(105%);transition:transform .28s ease;max-height:82vh;overflow-y:auto;}"
    + "#mdx-sheet.mdx-show{transform:translateY(0);}"
    + "#mdx-sheet h3{margin:0 0 4px;font-size:17px;color:#fff;}"
    + "#mdx-sheet .mdx-sub{font-size:12px;color:#9ca3af;margin-bottom:14px;}"
    + "#mdx-sheet .mdx-sec{font-size:13px;font-weight:bold;color:#a7f3ec;margin:16px 0 8px;}"
    + "#mdx-sheet .mdx-row{display:flex;align-items:center;justify-content:space-between;background:#26262e;"
    + "border-radius:12px;padding:11px 14px;margin-bottom:8px;font-size:14px;gap:8px;}"
    + "#mdx-sheet .mdx-meta{font-size:13px;color:#d1d5db;margin-bottom:6px;}"
    + "#mdx-sheet .mdx-meta b{color:#fff;}"
    + "#mdx-sheet .mdx-btn{flex:1;border:none;border-radius:12px;padding:12px;font-size:14px;cursor:pointer;margin:4px;font-family:inherit;}"
    + "#mdx-sheet .mdx-btn-p{background:linear-gradient(135deg,#35bfae,#7c5cff);color:#fff;}"
    + "#mdx-sheet .mdx-btn-s{background:#2d2d38;color:#e5e7eb;}"
    + "#mdx-sheet .mdx-btn-d{background:#3f1d24;color:#f87171;font-size:12px;padding:7px 12px;border-radius:9px;border:none;cursor:pointer;font-family:inherit;}"
    + "#mdx-sheet .mdx-btnrow{display:flex;margin-top:10px;}"
    + "#mdx-sheet .mdx-code{font-size:30px;letter-spacing:8px;text-align:center;color:#fff;background:#26262e;"
    + "border-radius:12px;padding:14px;margin:12px 0;direction:ltr;font-weight:bold;}"
    + "#mdx-sheet .mdx-cmd{direction:ltr;text-align:center;background:#111116;border-radius:9px;padding:8px;"
    + "font-size:13px;color:#a5b4fc;margin-top:8px;user-select:all;}"
    + "#mdx-sheet .mdx-linkbox{direction:ltr;text-align:left;background:#111116;border-radius:10px;padding:9px 10px;"
    + "font-size:11px;color:#7dd3fc;word-break:break-all;user-select:all;flex:1;}"
    + "#mdx-sheet .mdx-input{width:100%;box-sizing:border-box;background:#26262e;border:1px solid #3f3f4a;border-radius:12px;"
    + "padding:12px;font-size:16px;text-align:center;color:#fff;direction:ltr;margin:8px 0;font-family:inherit;}"
    + "#mdx-sheet .mdx-msg{font-size:13px;text-align:center;margin:8px 0;min-height:18px;}"
    + "#mdx-sheet .mdx-hint{font-size:12px;color:#9ca3af;line-height:1.8;}"
    + "#mdx-sheet .mdx-close{position:absolute;top:14px;left:14px;background:none;border:none;color:#9ca3af;font-size:20px;cursor:pointer;}";

  var styleEl = document.createElement("style");
  styleEl.textContent = css;
  document.head.appendChild(styleEl);

  var fab = document.createElement("button");
  fab.id = "mdx-fab"; fab.title = T.title; fab.textContent = "⚙️";
  document.body.appendChild(fab);
  var overlay = document.createElement("div");
  overlay.id = "mdx-overlay";
  document.body.appendChild(overlay);
  var sheet = document.createElement("div");
  sheet.id = "mdx-sheet";
  document.body.appendChild(sheet);

  function onAccountRoute() { return /\/account\/?$/.test(window.location.pathname); }
  function syncFab() { fab.classList.toggle("mdx-show", onAccountRoute()); }
  window.addEventListener("popstate", syncFab);

  /* ---- profile patcher: fix name/avatar rendered by the bundle ---- */
  var profileName = null;
  function loadProfileName() {
    if (profileName || !authHeader) return;
    api("session_info").then(function (res) {
      if (res && res.status && res.obj && res.obj.name) profileName = res.obj.name;
    }).catch(function () {});
  }
  function patchProfileDom() {
    if (!onAccountRoute()) return;
    if (!profileName) { loadProfileName(); return; }
    // Find the "نام:" label row and fix a placeholder value.
    var spans = document.querySelectorAll("span");
    for (var i = 0; i < spans.length; i++) {
      if (spans[i].textContent.trim() !== "نام:") continue;
      var row = spans[i].parentElement && spans[i].parentElement.parentElement;
      if (!row) continue;
      var valSpan = row.querySelector("span:last-child");
      if (valSpan) {
        var cur = valSpan.textContent.trim();
        if (cur === "" || cur === "none" || cur === "none none" || cur === "کاربر") {
          valSpan.textContent = profileName;
        }
      }
    }
    // Fix the avatar letter ("n" of "none").
    var avatar = document.querySelector(".rounded-full span.text-2xl");
    if (avatar && (avatar.textContent.trim() === "n" || avatar.textContent.trim() === "U" || avatar.textContent.trim() === "ک")) {
      avatar.textContent = profileName.charAt(0);
    }
  }
  setInterval(function () { syncFab(); patchProfileDom(); }, 700);

  function openSheet() {
    overlay.classList.add("mdx-show");
    sheet.classList.add("mdx-show");
    renderLoading();
    refresh();
  }
  function closeSheet() {
    overlay.classList.remove("mdx-show");
    sheet.classList.remove("mdx-show");
  }
  fab.addEventListener("click", openSheet);
  overlay.addEventListener("click", closeSheet);

  function renderLoading() {
    sheet.innerHTML = '<button class="mdx-close">✕</button><h3>' + T.title + '</h3><div class="mdx-msg">' + T.loading + "</div>";
    bindClose();
  }
  function bindClose() {
    var b = sheet.querySelector(".mdx-close");
    if (b) b.addEventListener("click", closeSheet);
  }
  function refresh() {
    api("session_info").then(function (res) {
      if (!res || !res.status) { renderError(res && res.msg); return; }
      renderMain(res.obj);
    }).catch(function () { renderError(null); });
  }
  function renderError(msg) {
    sheet.innerHTML = '<button class="mdx-close">✕</button><h3>' + T.title + '</h3>'
      + '<div class="mdx-msg">' + errText(msg || "error") + "</div>"
      + '<div class="mdx-btnrow"><button class="mdx-btn mdx-btn-s" id="mdx-retry">' + T.cancel + "</button></div>";
    bindClose();
    sheet.querySelector("#mdx-retry").addEventListener("click", closeSheet);
  }

  function renderMain(info) {
    var html = '<button class="mdx-close">✕</button>'
      + "<h3>" + T.title + "</h3>"
      + '<div class="mdx-sub">' + T.subtitle + "</div>"
      + '<div class="mdx-meta">' + T.currentPlatform + ': <b>' + (info.platform_label || info.platform) + "</b>"
      + ' &nbsp;•&nbsp; ' + T.primary + ': <b dir="ltr">' + info.primary + "</b></div>"

      /* profile: name + phone */
      + '<div class="mdx-sec">' + T.profile + "</div>"
      + '<div class="mdx-row"><span>👤 ' + (info.name || "") + "</span></div>"
      + '<div class="mdx-row"><span>📱 ' + (info.phone ? '<b dir="ltr">' + info.phone + "</b>" : '<span style="color:#9ca3af">' + T.phoneEmpty + "</span>")
      + '<button class="mdx-btn-d" style="background:#123; color:#7dd3fc" id="mdx-phonebtn">' + T.phoneAdd + "</button></span></div>"
      + '<div id="mdx-phoneform"></div>'

      /* persistent login link */
      + '<div class="mdx-sec">' + T.permLink + "</div>"
      + '<div class="mdx-row"><div class="mdx-linkbox" id="mdx-linkbox">' + (info.miniapp_url || "") + "</div>"
      + '<button class="mdx-btn-d" style="background:#123; color:#7dd3fc" id="mdx-copy">' + T.copy + "</button></div>"
      + '<div class="mdx-hint">' + T.permLinkHint + "</div>"
      + '<div class="mdx-btnrow"><button class="mdx-btn mdx-btn-s" id="mdx-regen">' + T.regenerate + "</button></div>"

      /* google */
      + '<div class="mdx-sec">' + T.google + "</div>"
      + (info.google_email
        ? '<div class="mdx-row"><span>' + T.googleLinked + ' <b dir="ltr">' + info.google_email + '</b></span><button class="mdx-btn-d" id="mdx-gunlink">' + T.googleUnlink + "</button></div>"
        : '<div class="mdx-btnrow" style="margin-top:0"><button class="mdx-btn mdx-btn-s" id="mdx-glink">' + T.googleLink + "</button></div>")

      /* linked platforms */
      + '<div class="mdx-sec">' + T.linkedTitle + "</div>";

    if (!info.links || info.links.length === 0) {
      html += '<div class="mdx-hint" style="margin-bottom:8px">' + T.noLinks + "</div>";
    } else {
      info.links.forEach(function (lnk) {
        html += '<div class="mdx-row"><span>' + (lnk.platform_label || lnk.platform)
          + ' <span dir="ltr" style="color:#9ca3af;font-size:12px">' + lnk.id + "</span></span>"
          + '<button class="mdx-btn-d" data-unlink="' + lnk.id + '">' + T.unlink + "</button></div>";
      });
    }

    html += '<div class="mdx-btnrow">'
      + '<button class="mdx-btn mdx-btn-p" id="mdx-getcode">' + T.getCode + "</button>"
      + '<button class="mdx-btn mdx-btn-s" id="mdx-entercode">' + T.enterCode + "</button>"
      + "</div>"
      + '<div class="mdx-msg" id="mdx-msg"></div>'
      + '<div id="mdx-body"></div>';

    sheet.innerHTML = html;
    bindClose();

    /* --- phone --- */
    sheet.querySelector("#mdx-phonebtn").addEventListener("click", function () {
      var box = sheet.querySelector("#mdx-phoneform");
      box.innerHTML = '<input class="mdx-input" id="mdx-phone" inputmode="tel" maxlength="14" placeholder="09123456789">'
        + '<div class="mdx-btnrow"><button class="mdx-btn mdx-btn-p" id="mdx-phonesave">' + T.phoneAdd + "</button>"
        + '<button class="mdx-btn mdx-btn-s" id="mdx-phonecancel">' + T.cancel + "</button></div>";
      sheet.querySelector("#mdx-phonecancel").addEventListener("click", function () { box.innerHTML = ""; });
      sheet.querySelector("#mdx-phonesave").addEventListener("click", function () {
        var phone = sheet.querySelector("#mdx-phone").value.trim();
        api("set_phone", { method: "POST", body: { actions: "set_phone", phone: phone } }).then(function (res) {
          var msg = sheet.querySelector("#mdx-msg");
          if (res && res.status) { msg.textContent = T.phoneSaved; box.innerHTML = ""; setTimeout(refresh, 700); }
          else msg.textContent = errText(res && res.msg);
        });
      });
    });

    /* --- persistent link --- */
    sheet.querySelector("#mdx-copy").addEventListener("click", function () {
      var url = sheet.querySelector("#mdx-linkbox").textContent;
      var done = function () {
        var msg = sheet.querySelector("#mdx-msg");
        msg.textContent = T.copied; setTimeout(function () { msg.textContent = ""; }, 1500);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(done, done);
      } else {
        var ta = document.createElement("textarea");
        ta.value = url; document.body.appendChild(ta); ta.select();
        try { document.execCommand("copy"); } catch (e) {}
        document.body.removeChild(ta); done();
      }
    });
    sheet.querySelector("#mdx-regen").addEventListener("click", function () {
      if (!window.confirm(T.confirmRegen)) return;
      api("key_regenerate", { method: "POST", body: { actions: "key_regenerate" } }).then(function (res) {
        var msg = sheet.querySelector("#mdx-msg");
        if (res && res.status) {
          sheet.querySelector("#mdx-linkbox").textContent = res.obj.miniapp_url;
          msg.textContent = T.regenerated;
        } else msg.textContent = errText(res && res.msg);
      });
    });

    /* --- google --- */
    var glink = sheet.querySelector("#mdx-glink");
    if (glink) glink.addEventListener("click", function () {
      api("google_link_url", { method: "POST", body: { actions: "google_link_url" } }).then(function (res) {
        if (res && res.status && res.obj.url) window.location.href = res.obj.url;
        else sheet.querySelector("#mdx-msg").textContent = errText(res && res.msg);
      });
    });
    var gunlink = sheet.querySelector("#mdx-gunlink");
    if (gunlink) gunlink.addEventListener("click", function () {
      api("google_unlink", { method: "POST", body: { actions: "google_unlink" } }).then(function (res) {
        if (res && res.status) refresh();
      });
    });

    /* --- platform links --- */
    sheet.querySelectorAll("[data-unlink]").forEach(function (btn) {
      btn.addEventListener("click", function () {
        if (!window.confirm(T.confirmUnlink)) return;
        api("link_unlink", { method: "POST", body: { actions: "link_unlink", linked_id: btn.getAttribute("data-unlink") } })
          .then(function (res) {
            var msg = sheet.querySelector("#mdx-msg");
            if (res && res.status) { msg.textContent = T.unlinkedOk; refresh(); }
            else msg.textContent = errText(res && res.msg);
          });
      });
    });

    sheet.querySelector("#mdx-getcode").addEventListener("click", function () {
      var body = sheet.querySelector("#mdx-body");
      body.innerHTML = "";
      api("link_issue", { method: "POST", body: { actions: "link_issue" } }).then(function (res) {
        var msg = sheet.querySelector("#mdx-msg");
        if (!res || !res.status) { msg.textContent = errText(res && res.msg); return; }
        msg.textContent = "";
        body.innerHTML = '<div class="mdx-hint">' + T.codeIssued + "</div>"
          + '<div class="mdx-code">' + res.obj.code + "</div>"
          + '<div class="mdx-hint">' + T.codeHint + "</div>"
          + '<div class="mdx-cmd">/start link_' + res.obj.code + "</div>"
          + '<div class="mdx-hint">' + T.codeHint2 + "</div>";
      });
    });

    sheet.querySelector("#mdx-entercode").addEventListener("click", function () {
      var body = sheet.querySelector("#mdx-body");
      body.innerHTML = '<div class="mdx-hint">' + T.askCode + "</div>"
        + '<input class="mdx-input" id="mdx-code" inputmode="numeric" maxlength="6" placeholder="______">'
        + '<div class="mdx-btnrow"><button class="mdx-btn mdx-btn-p" id="mdx-submit">' + T.submit + "</button>"
        + '<button class="mdx-btn mdx-btn-s" id="mdx-cancel">' + T.cancel + "</button></div>";
      var input = sheet.querySelector("#mdx-code");
      input.focus();
      sheet.querySelector("#mdx-cancel").addEventListener("click", function () { body.innerHTML = ""; });
      sheet.querySelector("#mdx-submit").addEventListener("click", function () {
        var code = input.value.trim();
        api("link_redeem", { method: "POST", body: { actions: "link_redeem", code: code } }).then(function (res) {
          var msg = sheet.querySelector("#mdx-msg");
          if (res && res.status) {
            msg.textContent = T.linkedOk;
            body.innerHTML = "";
            setTimeout(refresh, 900);
          } else {
            msg.textContent = errText(res && res.msg);
          }
        });
      });
    });
  }
})();
