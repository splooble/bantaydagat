










(function () {
    'use strict';

    var BASE = 'assets/idcard/';
    var CARD_W = 324, CARD_H = 204;

    var THEMES = {
        sjb:     { name: 'San Jose de Buenavista', main: '#1E5CB0', accent: '#4CF0ED', tab: '#82D2ED', seal: 'seal_sjb.png',     ill: 'ill_sjb.png', iw: 55, ih: 52,     office: 'Municipal Agriculture Office' },
        hamtic:  { name: 'Hamtic',                 main: '#2E7D4F', accent: '#8EF0B0', tab: '#9ADCB2', seal: 'seal_hamtic.png',  ill: 'ill_hamtic.png', iw: 52, ih: 52,  office: 'Municipal Agriculture Office' },
        tobias:  { name: 'Tobias Fornier',         main: '#0F7C8A', accent: '#5FF0E0', tab: '#86D6DC', seal: 'seal_tobias.png',  ill: 'ill_tobias.png', iw: 50, ih: 52,  office: 'Municipal Agriculture and Services Office' },
        aninity: { name: 'Anini-y',                main: '#B85414', accent: '#FFC27A', tab: '#F2C19A', seal: 'seal_aninity.png', ill: 'ill_aninity.png', iw: 62, ih: 43, office: 'Municipal Agriculture Office' }
    };

    function themeKey(mun) {
        var m = String(mun || '').toLowerCase();
        if (m.indexOf('hamtic') !== -1) return 'hamtic';
        if (m.indexOf('tobias') !== -1 || m.indexOf('dao') !== -1) return 'tobias';
        if (m.indexOf('anini') !== -1) return 'aninity';
        return 'sjb';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function fmtDate(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v || ''));
        if (!m || m[1] === '0000') return '—';
        return MONTHS[+m[2] - 1] + ' ' + (+m[3]) + ', ' + m[1];
    }


    function injectStyles() {
        if (document.getElementById('idc-styles')) return;
        var css = [
            '.idc-wrap{width:' + CARD_W + 'px;height:' + CARD_H + 'px;margin:0 auto;border-radius:10px;overflow:hidden;box-shadow:0 6px 22px rgba(10,47,79,.22);}',
            '.idc-card{position:relative;width:' + CARD_W + 'px;height:' + CARD_H + 'px;overflow:hidden;background-size:100% 100%;background-repeat:no-repeat;font-family:"Sora","Instrument Sans",system-ui,sans-serif;color:#0b1b2b;text-rendering:geometricPrecision;-webkit-font-smoothing:antialiased;}',
            '.idc-card *{box-sizing:border-box;margin:0;padding:0;}',
            '.idc-card .a{position:absolute;}',
            '.idc-hd{left:0;top:0;width:100%;}',
            '.idc-fit{white-space:nowrap;}',
            '.idc-title{color:#fff;font-weight:400;font-size:13.2px;line-height:14px;letter-spacing:.2px;white-space:nowrap;}',
            '.idc-sub{color:#fff;font-weight:400;font-size:6.3px;line-height:8px;white-space:nowrap;}',
            '.idc-photo{border-radius:6px;border:1.5px solid;background-size:cover;background-position:center;background-repeat:no-repeat;background-color:#dbeaf5;overflow:hidden;}',
            '.idc-photo .ini{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:38px;color:rgba(255,255,255,.95);}',
            '.idc-name{text-align:center;font-weight:800;font-size:18px;line-height:24px;text-transform:uppercase;color:#050d16;}',
            '.idc-role{text-align:center;font-family:"Inter","Instrument Sans",system-ui,sans-serif;font-size:10px;line-height:12px;color:#111;}',
            '.idc-lbl{display:block;font-weight:600;font-size:5.4px;line-height:7px;letter-spacing:.55px;text-transform:uppercase;}',
            '.idc-regv{display:block;font-weight:700;font-size:13px;line-height:1.26;color:#050d16;text-align:center;}',
            '.idc-cell .idc-lbl{color:#3f6482;}',
            '.idc-cell .v{display:block;font-weight:600;font-size:8.4px;line-height:1.26;color:#0b1b2b;}',
            '.idc-cell .v.idc-fit{width:98px;}',
            '.idc-rule{height:1px;}',
            '.idc-backtitle{color:#fff;text-align:center;font-weight:400;font-size:15px;line-height:20px;letter-spacing:.5px;white-space:nowrap;}',
            '.idc-emg{border-radius:5px;background:rgba(255,255,255,.5);overflow:hidden;}',
            '.idc-emg .th{height:15px;line-height:15px;text-align:center;font-weight:700;font-size:8.4px;letter-spacing:.2px;color:#0b1b2b;}',
            '.idc-emg .row{height:12px;line-height:1.26;padding-left:7px;white-space:nowrap;font-size:7.4px;display:flex;align-items:center;}',
            '.idc-emg .row .k{display:inline-block;width:66px;flex:none;font-weight:400;}',
            '.idc-emg .row .val{display:inline-block;width:84px;font-weight:700;font-size:7.9px;}',
            '.idc-auth{text-align:center;color:#1a1a1a;}',
            '.idc-auth .s{font-size:6px;line-height:8px;font-weight:400;}',
            '.idc-auth .o{font-size:7.8px;line-height:9px;font-weight:700;color:#000;}',
            '.idc-found{text-align:center;font-size:5.2px;line-height:6.6px;color:#3d5a73;font-weight:400;}',
            '.idc-qr{background:#fff;border-radius:4px;padding:3px;}',
            '.idc-qr img{display:block;width:74px;height:74px;}',
            '.idc-scan{text-align:center;font-weight:700;font-size:6.2px;line-height:8px;letter-spacing:.2px;white-space:nowrap;}',
            '.idc-para{text-align:center;font-weight:400;font-size:5.4px;line-height:7px;color:#1a1a1a;}',
            '.idc-sides{display:flex;flex-direction:column;gap:14px;align-items:center;}',
            '.idc-cap{font-size:10px;font-weight:600;color:#5a7185;text-align:center;margin:0 0 5px;letter-spacing:.04em;text-transform:uppercase;}',
            '#idc-print-root{display:none;}',
            '@media print{',
            '  @page{size:85.6mm 54mm;margin:0;}',
            '  html,body{margin:0 !important;padding:0 !important;background:#fff !important;}',
            '  body > *:not(#idc-print-root){display:none !important;}',
            '  #idc-print-root{display:block !important;}',
            '  #idc-print-root, #idc-print-root *{-webkit-print-color-adjust:exact !important;print-color-adjust:exact !important;}',
            '  .idc-page{width:85.6mm;height:54mm;overflow:hidden;position:relative;page-break-after:always;break-after:page;}',
            '  .idc-page:last-child{page-break-after:auto;break-after:auto;}',
            '  .idc-page .idc-card{transform:scale(.99855);transform-origin:0 0;}',
            '}'
        ].join('\n');
        var st = document.createElement('style');
        st.id = 'idc-styles';
        st.textContent = css;
        document.head.appendChild(st);
    }


    function frontHtml(ff, t) {
        var fullName = [ff.first_name, ff.middle_name, ff.last_name, ff.suffix]
            .filter(function (x) { return x && String(x).trim(); }).join(' ');
        var initial = (ff.first_name || ff.last_name || '?').trim().charAt(0).toUpperCase();
        var photo = ff.photo_path
            ? '<div class="idc-photo a" style="left:14px;top:77px;width:85px;height:108px;border-color:' + t.main + ';background-image:url(\'uploads/photos/' + encodeURIComponent(ff.photo_path) + '\');"></div>'
            : '<div class="idc-photo a" style="left:14px;top:77px;width:85px;height:108px;border-color:' + t.main + ';background:' + t.main + ';"><div class="ini">' + esc(initial) + '</div></div>';

        function cell(left, top, label, value) {
            return '<div class="idc-cell a" style="left:' + left + 'px;top:' + top + 'px;width:98px;">' +
                '<span class="idc-lbl">' + esc(label) + '</span>' +
                '<span class="v idc-fit" data-max="8.4" data-min="5">' + esc(value || '—') + '</span></div>';
        }

        return '' +
            '<div class="idc-hd a" style="height:61px;background:' + t.main + ';border-bottom:2px solid ' + t.accent + ';"></div>' +
            '<img class="a" src="' + BASE + t.seal + '" alt="" style="left:10px;top:10px;width:41px;height:41px;">' +
            '<div class="idc-title a" style="left:62px;top:13px;">MUNICIPAL FISHERFOLK<br>RFID IDENTIFICATION CARD</div>' +
            '<div class="idc-sub a" style="left:62px;top:45px;">Municipality of ' + esc(t.name) + ', Antique</div>' +
            '<img class="a" src="' + BASE + 'logo.png" alt="" style="left:277px;top:11px;height:38px;width:28px;">' +
            photo +
            '<div class="idc-name idc-fit a" data-max="18" data-min="13" data-wrap="1" style="left:108px;top:68px;width:208px;height:24px;">' + esc(fullName) + '</div>' +
            '<div class="idc-role a" style="left:108px;top:95px;width:208px;">Municipal Fisherfolk</div>' +
            '<div class="a" style="left:108px;top:112px;width:208px;">' +
                '<span class="idc-lbl" style="color:' + t.main + ';text-align:center;">Fisherfolk Registry No.</span>' +
                '<span class="idc-regv idc-fit" data-max="13" data-min="7.5">' + esc(ff.brn || ff.boat_registry_number || '—') + '</span>' +
            '</div>' +
            '<div class="idc-rule a" style="left:112px;top:138px;width:200px;background:' + t.main + ';opacity:.3;"></div>' +
            cell(112, 143, 'Barangay', ff.barangay) +
            cell(214, 143, 'Date registered', fmtDate(ff.created_at)) +
            cell(112, 166, 'Birthdate', fmtDate(ff.birthdate)) +
            cell(214, 166, 'Sex', ff.sex);
    }

    function backHtml(ff, t, qrDataUrl) {
        var office = ff.id_office || t.office;
        return '' +
            '<div class="idc-hd a" style="height:40px;background:' + t.main + ';border-bottom:2px solid ' + t.accent + ';"></div>' +
            '<div class="idc-backtitle a" style="left:0;top:9px;width:100%;">REGISTRATION INFORMATION</div>' +
            '<div class="a" style="left:181px;top:42px;width:1px;height:162px;background:' + t.tab + ';"></div>' +
            '<div class="idc-emg a" style="left:14px;top:54px;width:160px;height:56px;">' +
                '<div class="th" style="background:' + t.tab + ';">EMERGENCY CONTACT</div>' +
                '<div class="row"><span class="k">Name:</span><span class="val idc-fit" data-max="7.9" data-min="5">' + esc(ff.emergency_name || '—') + '</span></div>' +
                '<div class="row"><span class="k">Relationship:</span><span class="val idc-fit" data-max="7.9" data-min="5">' + esc(ff.emergency_relationship || '—') + '</span></div>' +
                '<div class="row"><span class="k">Contact Number:</span><span class="val idc-fit" data-max="7.9" data-min="5">' + esc(ff.emergency_contact || '—') + '</span></div>' +
            '</div>' +
            '<div class="a" style="left:14px;top:118px;width:160px;height:1px;background:' + t.tab + ';"></div>' +
            '<img class="a" src="' + BASE + t.ill + '" alt="" style="left:2px;bottom:0;width:' + t.iw + 'px;height:' + t.ih + 'px;">' +
            '<div class="idc-auth a" style="left:58px;top:123px;width:122px;">' +
                '<div class="s">Authorized by:</div>' +
                '<div class="o">' + esc(office) + '</div>' +
                '<div class="s">' + esc(t.name) + ', Antique</div>' +
            '</div>' +
            '<div class="idc-found a" style="left:58px;top:171px;width:122px;">If found, please return to the ' + esc(office) + '.</div>' +
            '<div class="idc-qr a" style="left:213px;top:50px;width:80px;height:80px;">' +
                (qrDataUrl ? '<img src="' + qrDataUrl + '" alt="QR code">' : '') +
            '</div>' +
            '<div class="idc-scan a" style="left:182px;top:140px;width:142px;color:' + t.main + ';">SCAN QR OR TAP RFID TO VERIFY</div>' +
            '<div class="idc-para a" style="left:190px;top:154px;width:126px;">This credential provides authorized personnel access to the fisherfolk\u2019s verification record, permit information, boat details, and compliance verification.</div>';
    }


    function makeQrDataUrl(token) {
        return new Promise(function (resolve) {
            var tmp = document.createElement('div');
            tmp.style.cssText = 'position:fixed;left:-9999px;top:0;';
            document.body.appendChild(tmp);
            try {
                new QRCode(tmp, { text: token, width: 296, height: 296, colorDark: '#000000', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
            } catch (e) {
                document.body.removeChild(tmp);
                resolve(null);
                return;
            }
            var tries = 0;
            (function poll() {
                var c = tmp.querySelector('canvas');
                if (c) {
                    var url = null;
                    try { url = c.toDataURL('image/png'); } catch (e) {}
                    document.body.removeChild(tmp);
                    resolve(url);
                } else if (++tries > 60) {
                    document.body.removeChild(tmp);
                    resolve(null);
                } else {
                    setTimeout(poll, 25);
                }
            })();
        });
    }

    function waitImages(root) {
        var imgs = Array.prototype.slice.call(root.querySelectorAll('img'));
        return Promise.all(imgs.map(function (im) {
            if (im.complete && im.naturalWidth) return null;
            return new Promise(function (res) { im.onload = im.onerror = function () { res(); }; });
        }));
    }

    function waitBgImages(root) {
        var urls = [];
        root.querySelectorAll('.idc-card, .idc-photo').forEach(function (el) {
            var m = /url\(["']?([^"')]+)["']?\)/.exec(el.style.backgroundImage || '');
            if (m) urls.push(m[1]);
        });
        return Promise.all(urls.map(function (u) {
            return new Promise(function (res) { var i = new Image(); i.onload = i.onerror = function () { res(); }; i.src = u; });
        }));
    }

    function loadFonts() {
        if (!document.fonts || !document.fonts.load) return Promise.resolve();
        return Promise.all([
            document.fonts.load('400 13px "Sora"'), document.fonts.load('600 13px "Sora"'),
            document.fonts.load('700 13px "Sora"'), document.fonts.load('800 18px "Sora"'),
            document.fonts.load('400 10px "Inter"')
        ]).catch(function () {}).then(function () { return document.fonts.ready; });
    }


    function fitText(root) {
        root.querySelectorAll('.idc-fit').forEach(function (el) {
            var max = parseFloat(el.getAttribute('data-max')) || 10;
            var min = parseFloat(el.getAttribute('data-min')) || 5;
            var size = max;
            el.style.whiteSpace = 'nowrap';
            el.style.lineHeight = '';
            el.style.fontSize = size + 'px';
            while (el.scrollWidth > el.clientWidth + 0.5 && size > min) {
                size -= 0.25;
                el.style.fontSize = size + 'px';
            }

            if (el.getAttribute('data-wrap') && el.scrollWidth > el.clientWidth + 0.5) {
                var h = el.clientHeight || 24;
                size = 11.5;
                el.style.whiteSpace = 'normal';
                el.style.lineHeight = '1.06';
                el.style.fontSize = size + 'px';
                while (el.scrollHeight > h + 0.5 && size > 7) {
                    size -= 0.25;
                    el.style.fontSize = size + 'px';
                }
            }
        });
    }


    function render(ff, token, brn, container) {
        injectStyles();
        var data = Object.assign({}, ff, { brn: brn || ff.boat_registry_number });
        var key = themeKey(ff.municipality);
        var t = THEMES[key];

        return Promise.all([makeQrDataUrl(token), loadFonts()]).then(function (r) {
            var qr = r[0];
            container.innerHTML =
                '<div class="idc-sides">' +
                    '<div><p class="idc-cap">Front</p><div class="idc-wrap"><div class="idc-card idc-front" style="background-image:url(\'' + BASE + 'bg_front_' + key + '.jpg\');">' + frontHtml(data, t) + '</div></div></div>' +
                    '<div><p class="idc-cap">Back</p><div class="idc-wrap"><div class="idc-card idc-back" style="background-image:url(\'' + BASE + 'bg_back_' + key + '.jpg\');">' + backHtml(data, t, qr) + '</div></div></div>' +
                '</div>';
            return Promise.all([waitImages(container), waitBgImages(container)]);
        }).then(function () {
            fitText(container);
            return { qrOk: !!container.querySelector('.idc-qr img') };
        });
    }


    function buildPrintRoot(container) {
        var front = container.querySelector('.idc-front');
        var back = container.querySelector('.idc-back');
        if (!front || !back) return null;
        var old = document.getElementById('idc-print-root');
        if (old) old.parentNode.removeChild(old);
        var root = document.createElement('div');
        root.id = 'idc-print-root';
        [front, back].forEach(function (card) {
            var page = document.createElement('div');
            page.className = 'idc-page';
            page.appendChild(card.cloneNode(true));
            root.appendChild(page);
        });
        document.body.appendChild(root);
        return root;
    }

    function print(container) {
        injectStyles();
        fitText(container);
        var root = buildPrintRoot(container);
        if (!root) return Promise.reject(new Error('Card is not rendered yet.'));
        var cleanup = function () {
            window.removeEventListener('afterprint', cleanup);
            var r = document.getElementById('idc-print-root');
            if (r && r.parentNode) r.parentNode.removeChild(r);
        };
        window.addEventListener('afterprint', cleanup);
        return loadFonts().then(function () {
            return new Promise(function (resolve) {
                setTimeout(function () { window.print(); resolve(); }, 150);
            });
        });
    }

    window.IDCard = { render: render, print: print, _buildPrintRoot: buildPrintRoot, themes: THEMES };
})();