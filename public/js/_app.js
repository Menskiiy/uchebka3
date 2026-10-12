/* ПРОМИКИ.РУ — фронтенд без зависимостей. */

(function () {
    'use strict';

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var BASE_URL = (document.querySelector('meta[name="base-url"]') || {}).content || '';

    var csrf = function () {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    };

    /* ------------------------------------------------------------ тема */

    window.toggleTheme = function () {
        var html = document.documentElement;
        var next = html.dataset.theme === 'dark' ? 'light' : 'dark';

        // включаем переходы только на время переключения,
        // иначе они мешают всем остальным анимациям
        html.classList.add('theme-switching');
        html.dataset.theme = next;
        localStorage.setItem('promiki-theme', next);

        setTimeout(function () { html.classList.remove('theme-switching'); }, 400);
    };

    /* ------------------------------------------------------- дропдауны */

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-dropdown]');
        var inMenu = e.target.closest('.dropdown__menu');

        document.querySelectorAll('.dropdown.is-open').forEach(function (el) {
            // своя кнопка — обработаем ниже; клик внутри меню не закрываем,
            // иначе нельзя заполнить форму фильтров
            if (trigger && el === trigger.closest('.dropdown')) return;
            if (inMenu && el.contains(inMenu)) return;

            el.classList.remove('is-open');
        });

        if (trigger) {
            e.preventDefault();
            trigger.closest('.dropdown').classList.toggle('is-open');
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.dropdown.is-open').forEach(function (el) {
                el.classList.remove('is-open');
            });
        }
    });

    /* ------------------------------------------- волна по кнопкам */

    document.addEventListener('pointerdown', function (e) {
        if (reduced) return;

        // кнопка промокода не имеет класса .btn (у неё своя форма),
        // но вести себя при нажатии должна так же, как «К скидке»
        var btn = e.target.closest('.btn, .code-box__btn');
        if (!btn) return;

        var rect = btn.getBoundingClientRect();
        var size = Math.max(rect.width, rect.height);
        var ripple = document.createElement('span');

        ripple.className = 'ripple';
        ripple.style.width = ripple.style.height = size + 'px';
        ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
        ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';

        btn.appendChild(ripple);
        setTimeout(function () { ripple.remove(); }, 600);
    });

    /* --------------------------------------------------- голосование */

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-vote]');
        if (!btn) return;

        e.preventDefault();

        var widget = btn.closest('.temp');
        var url = widget.dataset.url;

        if (!url) {
            window.location = widget.dataset.login;
            return;
        }

        var value = widget.querySelector('.temp__value');
        var before = parseInt(value.textContent, 10) || 0;

        var body = new URLSearchParams();
        body.append('direction', btn.dataset.vote);
        body.append('_token', csrf());

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body.toString()
        }).then(function (r) {
            return r.json();
        }).then(function (data) {
            if (data.error) { alert(data.error); return; }

            value.textContent = data.temperature + '\u00B0';
            value.classList.toggle('is-hot', data.temperature > 0);
            value.classList.toggle('is-cold', data.temperature < 0);

            widget.querySelectorAll('[data-vote]').forEach(function (b) {
                b.classList.toggle('is-active', b.dataset.vote === data.direction);
            });

            if (!reduced) {
                value.classList.remove('is-bumped');
                void value.offsetWidth;          // перезапуск анимации
                value.classList.add('is-bumped');

                var delta = data.temperature - before;

                if (delta !== 0) {
                    var float = document.createElement('span');
                    float.className = 'temp__float';
                    float.textContent = (delta > 0 ? '+' : '') + delta + '\u00B0';
                    float.style.color = delta > 0 ? 'var(--hot)' : 'var(--cold)';
                    widget.appendChild(float);
                    setTimeout(function () { float.remove(); }, 950);
                }
            }

            widget.classList.toggle('is-blazing', data.temperature >= 100);
        }).catch(function (err) {
            console.error(err);
        });
    });

    /* ------------------------------------------ промокод: показать и скопировать */

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.code-box__btn');
        if (!btn) return;

        // кнопка — ссылка на страницу кода, она нужна только без скриптов
        e.preventDefault();

        var box = btn.closest('.code-box');
        var code = box.dataset.code || '';
        var coupon = box.dataset.coupon || '';
        var go = box.dataset.go || '';

        // уже открыт — просто копируем ещё раз, никуда не уводим
        if (box.classList.contains('is-open')) {
            copyCode(box, code, btn);
            return;
        }

        box.classList.add('is-open');
        box.querySelector('.code-box__value').textContent = code;
        btn.textContent = 'Копировать';

        copyCode(box, code, btn);

        /*
         * Вкладки меняются ролями. Открыть новую в фоне браузер не даёт —
         * фокус всегда уходит на неё. Поэтому в новой вкладке открываем копию
         * той же страницы: тот же адрес, то же место, промокод уже открыт.
         * Для человека это выглядит так, будто ничего не произошло, а магазин
         * тем временем грузится в старой вкладке, куда он не смотрит.
         *
         * Метка в адресе говорит копии, какой код раскрыть; сразу после этого
         * она убирается, и адрес становится в точности прежним.
         *
         * Окно открываем прямо в обработчике клика, иначе браузер посчитает
         * его всплывающим и заблокирует. Уход текущей вкладки задерживаем:
         * если уводить сразу, часть браузеров отменяет открытие новой вместе
         * с уходом со страницы.
         */
        var slug = coupon ? coupon.split('/').pop() : '';

        window.open(
            window.location.pathname + window.location.search + (slug ? '#promokod-' + slug : ''),
            '_blank',
            'noopener'
        );

        if (go) {
            reachGoal('go_to_shop', { slug: go.split('/go/').pop(), source: 'promokod' });

            setTimeout(function () { window.location.href = go; }, 150);
        }
    });

    /* страница промокода: копируем сразу при открытии */

    var couponBox = document.getElementById('coupon-code');

    if (couponBox) {
        var couponCode = couponBox.dataset.code || '';
        var couponBtn = couponBox.querySelector('[data-coupon-copy]');

        copyCode(couponBox, couponCode, couponBtn);

        couponBtn.addEventListener('click', function () {
            copyCode(couponBox, couponCode, couponBtn);
        });
    }

    function copyCode(box, code, btn) {
        var done = function () {
            if (!btn) return;

            var prev = btn.dataset.label || btn.textContent;
            btn.dataset.label = prev;
            btn.textContent = 'Скопировано';
            btn.classList.add('is-copied');

            setTimeout(function () {
                btn.textContent = prev;
                btn.classList.remove('is-copied');
            }, 1800);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code).then(done).catch(function () { fallbackCopy(code, done); });
        } else {
            fallbackCopy(code, done);
        }
    }

    // запасной способ для старых браузеров и незащищённого соединения
    function fallbackCopy(text, done) {
        var input = document.createElement('textarea');
        input.value = text;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();

        try { document.execCommand('copy'); done(); } catch (err) { /* тихо */ }

        document.body.removeChild(input);
    }

    /* ------------------------------------------- ответ на комментарий */

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-reply], [data-edit]');
        if (!btn) return;

        var id = btn.dataset.reply || btn.dataset.edit;
        var form = document.getElementById((btn.dataset.edit ? 'edit-' : 'reply-') + id);

        if (!form) return;

        // открыта может быть только одна форма: две подряд под одним
        // комментарием читаются как ошибка вёрстки
        var other = document.getElementById((btn.dataset.edit ? 'reply-' : 'edit-') + id);

        if (other) other.classList.remove('is-open');

        form.classList.toggle('is-open');

        var area = form.querySelector('.editor__area');

        if (area && form.classList.contains('is-open')) area.focus();
    });

    /* ------------------------------- появление карточек при прокрутке */

    if (!reduced && 'IntersectionObserver' in window) {
        var cards = Array.prototype.slice.call(document.querySelectorAll('.thread'));

        if (cards.length > 5) {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-in');
                        observer.unobserve(entry.target);
                    }
                });
            }, { rootMargin: '0px 0px -40px 0px' });

            cards.slice(5).forEach(function (card) {
                card.style.animation = 'none';   // первичная анимация не нужна
                card.classList.add('reveal');
                observer.observe(card);
            });
        }
    }

    /* ------------------------------------ счётчики на сводке админки */

    document.querySelectorAll('.stat__value[data-count]').forEach(function (el) {
        var target = parseInt(el.dataset.count, 10) || 0;

        if (reduced || target === 0) {
            el.textContent = target;
            return;
        }

        var started = null;
        var duration = Math.min(900, 260 + target * 12);

        var step = function (now) {
            if (!started) started = now;

            var progress = Math.min(1, (now - started) / duration);
            var eased = 1 - Math.pow(1 - progress, 3);

            el.textContent = Math.round(target * eased);

            if (progress < 1) requestAnimationFrame(step);
        };

        requestAnimationFrame(step);
    });

    /* ------------------------------------------ автоскрытие сообщений */

    document.querySelectorAll('.alert').forEach(function (el) {
        if (el.classList.contains('alert--error')) return;

        setTimeout(function () {
            el.classList.add('is-fading');
            setTimeout(function () { el.remove(); }, 450);
        }, 4500);
    });

    /* ------------------------------------- предпросмотр аватара */

    var avatarInput = document.getElementById('avatar-input');

    if (avatarInput) {
        avatarInput.addEventListener('change', function () {
            var file = this.files && this.files[0];
            if (!file) return;

            var preview = document.getElementById('avatar-preview');
            var reader = new FileReader();

            reader.onload = function (ev) {
                preview.innerHTML = '<img src="' + ev.target.result + '" alt="">';
            };

            reader.readAsDataURL(file);
        });
    }

    /* ------------------------- выдвижная панель формы в админке */

    /*
     * На телефоне правая колонка с формой уезжала под длинный список.
     * Кнопка в шапке выдвигает её поверх содержимого; затемнение и Esc
     * закрывают. На широком экране кнопка скрыта — панель и так на виду.
     */
    (function () {
        var panel = document.querySelector('.admin__sticky');
        var button = document.querySelector('[data-panel-toggle]');

        if (!panel || !button) return;

        var backdrop = document.createElement('div');

        backdrop.className = 'admin__panel-back';
        document.body.appendChild(backdrop);

        var setOpen = function (open) {
            panel.classList.toggle('is-open', open);
            backdrop.classList.toggle('is-open', open);
            document.body.classList.toggle('is-panel-open', open);
            button.setAttribute('aria-expanded', open ? 'true' : 'false');

            // открываем всегда сверху: панель могла остаться прокрученной
            // с прошлого раза, и форма начиналась бы с середины
            if (open) panel.scrollTop = 0;
        };

        button.addEventListener('click', function () {
            setOpen(!panel.classList.contains('is-open'));
        });

        backdrop.addEventListener('click', function () { setOpen(false); });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setOpen(false);
        });
    })();

    /* ------------------------------ выдвижное меню админки на телефоне */

    var shell = document.getElementById('admin-shell');

    if (shell) {
        var burger = shell.querySelector('[data-drawer-toggle]');

        var setDrawer = function (open) {
            shell.classList.toggle('is-drawer-open', open);
            document.body.classList.toggle('is-locked', open);

            if (burger) {
                burger.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
        };

        if (burger) {
            burger.addEventListener('click', function () {
                setDrawer(!shell.classList.contains('is-drawer-open'));
            });
        }

        // затемнение и пункты меню закрывают панель
        shell.addEventListener('click', function (e) {
            if (e.target.closest('[data-drawer-close]')) {
                setDrawer(false);
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') setDrawer(false);
        });

        // при возврате на широкий экран блокировка прокрутки не должна остаться
        window.addEventListener('resize', function () {
            if (window.innerWidth > 900) setDrawer(false);
        });
    }

    /* ------------------------------------------ вкладки: бегунок и залипание */

    /**
     * Шапка на десктопе липкая, на телефоне — нет. Поэтому отступ, на котором
     * останавливаются вкладки, считаем из её реальной высоты и режима.
     */
    var syncStickyTop = function () {
        var header = document.querySelector('.header');
        var top = 0;

        if (header && getComputedStyle(header).position === 'sticky') {
            top = header.offsetHeight;
        }

        document.documentElement.style.setProperty('--sticky-top', top + 'px');
    };

    var tabBars = Array.prototype.slice.call(document.querySelectorAll('.tabs'));

    tabBars.forEach(function (bar) {
        var marker = document.createElement('span');
        marker.className = 'tabs__marker';
        bar.insertBefore(marker, bar.firstChild);
        bar.classList.add('has-marker');

        var moveTo = function (link, animate) {
            if (!link) {
                marker.style.opacity = '0';
                return;
            }

            marker.style.opacity = '1';

            if (!animate) {
                var saved = marker.style.transition;
                marker.style.transition = 'none';
                marker.style.width = link.offsetWidth + 'px';
                marker.style.transform = 'translateX(' + link.offsetLeft + 'px)';
                void marker.offsetWidth;
                marker.style.transition = saved;
                return;
            }

            marker.style.width = link.offsetWidth + 'px';
            marker.style.transform = 'translateX(' + link.offsetLeft + 'px)';
        };

        var current = function () {
            return bar.querySelector('a.is-active');
        };

        moveTo(current(), false);

        // бегунок уезжает сразу по клику — до того, как загрузится страница
        bar.addEventListener('click', function (e) {
            var link = e.target.closest('a');
            if (!link || !bar.contains(link)) return;

            bar.querySelectorAll('a').forEach(function (a) {
                a.classList.toggle('is-active', a === link);
            });

            moveTo(link, true);
        });

        bar.addEventListener('scroll', function () { moveTo(current(), false); });

        window.addEventListener('resize', function () { moveTo(current(), false); });
    });

    var stickyBars = document.querySelectorAll('.tabs--sticky, .feed-bar');

    if (stickyBars.length) {
        var ticking = false;

        var checkStuck = function () {
            var offset = parseInt(getComputedStyle(document.documentElement)
                .getPropertyValue('--sticky-top'), 10) || 0;

            stickyBars.forEach(function (bar) {
                bar.classList.toggle('is-stuck', bar.getBoundingClientRect().top <= offset + 1);
            });

            ticking = false;
        };

        window.addEventListener('scroll', function () {
            if (!ticking) {
                ticking = true;
                requestAnimationFrame(checkStuck);
            }
        }, { passive: true });

        checkStuck();
    }

    syncStickyTop();
    window.addEventListener('resize', syncStickyTop);
    window.addEventListener('load', syncStickyTop);

    /* ------------------------------------------ обратный отсчёт до конца акции */

    var countdowns = document.querySelectorAll('.expiry[data-expires]');

    if (countdowns.length) {
        var plural = function (n, one, few, many) {
            var n100 = Math.abs(n) % 100;
            var n10 = n100 % 10;

            if (n100 > 10 && n100 < 20) return many;
            if (n10 > 1 && n10 < 5) return few;
            if (n10 === 1) return one;

            return many;
        };

        var format = function (left) {
            var days = Math.floor(left / 86400);
            var hours = Math.floor((left % 86400) / 3600);
            var mins = Math.floor((left % 3600) / 60);

            if (days > 0) {
                return days + ' ' + plural(days, 'день', 'дня', 'дней') + (hours ? ' ' + hours + ' ч' : '');
            }

            if (hours > 0) {
                return hours + ' ч' + (mins ? ' ' + mins + ' мин' : '');
            }

            var secs = left % 60;
            return mins + ' мин' + (mins < 10 ? ' ' + secs + ' с' : '');
        };

        var tick = function () {
            var now = Date.now();
            var soonest = Infinity;

            countdowns.forEach(function (el) {
                // строку даты приводим к виду, который одинаково понимают
                // и Safari, и Chrome: без замены пробела Safari вернёт NaN
                var target = new Date(el.dataset.expires.replace(' ', 'T')).getTime();
                var left = Math.floor((target - now) / 1000);
                var text = el.querySelector('.expiry__text');

                if (!text) return;

                if (left <= 0) {
                    el.classList.remove('expiry--soon');
                    el.classList.add('expiry--over');
                    text.textContent = 'Предложение истекло';
                    el.removeAttribute('data-expires');
                    return;
                }

                if (left <= 7 * 86400) {
                    text.textContent = 'Осталось ' + format(left);
                    el.classList.toggle('expiry--soon', left <= 86400);
                }

                soonest = Math.min(soonest, left);
            });

            // близко к концу тикаем ежесекундно, иначе раз в минуту
            return soonest < 3600 ? 1000 : 60000;
        };

        var schedule = function () {
            var delay = tick();
            setTimeout(schedule, delay);
        };

        schedule();
    }

    /* ------------------------------------- быстрый выбор срока действия */

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-days][data-target]');
        if (!btn) return;

        var field = document.getElementById(btn.dataset.target);
        if (!field) return;

        if (btn.dataset.days === '') {
            field.value = '';
        } else {
            var d = new Date();
            d.setDate(d.getDate() + parseInt(btn.dataset.days, 10));
            // toISOString отдаёт UTC и может сместить дату на сутки,
            // поэтому собираем строку из локальных частей
            field.value = d.getFullYear() + '-'
                + String(d.getMonth() + 1).padStart(2, '0') + '-'
                + String(d.getDate()).padStart(2, '0');
        }

        btn.closest('.date-presets').querySelectorAll('[data-days]').forEach(function (b) {
            b.classList.toggle('is-active', b === btn);
        });
    });

    /* ------------------------------------------ «поделиться»: ссылка */

    /*
     * На телефоне сначала пробуем системное меню «Поделиться»: оттуда
     * ссылка уходит в любой мессенджер, а не только в три соцсети.
     * Если его нет — копируем в буфер обмена.
     */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-share-copy]');
        if (!btn) return;

        var url = btn.dataset.shareCopy;
        var name = btn.querySelector('.share__name');

        var done = function () {
            if (!name) return;

            var was = name.textContent;

            name.textContent = 'Скопировано';
            btn.classList.add('is-copied');

            setTimeout(function () {
                name.textContent = was;
                btn.classList.remove('is-copied');
            }, 1800);
        };

        if (navigator.share) {
            navigator.share({ title: document.title, url: url }).catch(function () {});

            return;
        }

        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(done).catch(function () {});

            return;
        }

        // старые браузеры: временное поле и старая добрая команда копирования
        var field = document.createElement('input');

        field.value = url;
        document.body.appendChild(field);
        field.select();

        try { document.execCommand('copy'); done(); } catch (err) {}

        document.body.removeChild(field);
    });

    /* --------------------------------- согласие на cookie и аналитика */

    var CONSENT_KEY = 'promiki-cookie';

    var loadMetrika = function () {
        var id = window.PROMIKI_METRIKA;
        if (!id || window.ym) return;

        (function (m, e, t, r, i, k, a) {
            m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
            m[i].l = 1 * new Date();

            for (var j = 0; j < e.scripts.length; j++) {
                if (e.scripts[j].src === r) return;
            }

            k = e.createElement(t); a = e.getElementsByTagName(t)[0];
            k.async = 1; k.src = r; a.parentNode.insertBefore(k, a);
        })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js?id=' + id, 'ym');

        window.ym(id, 'init', {
            ssr: true, webvisor: true, clickmap: true,
            accurateTrackBounce: true, trackLinks: true,
            referrer: document.referrer, url: location.href
        });
    };

    var consent = localStorage.getItem(CONSENT_KEY);
    var banner = document.getElementById('cookie-banner');

    /*
     * Счётчик запускается только после согласия. Раньше он работал сразу,
     * а согласие лишь «подтверждало» уже собранное — так в политике написать
     * нечего. Теперь до нажатия кнопки аналитические cookie не ставятся,
     * и это ровно то, что заявлено в политике использования файлов cookie.
     */
    if (consent === 'yes') {
        loadMetrika();
    }

    var showBanner = function () {
        if (!banner) return;

        banner.hidden = false;
        requestAnimationFrame(function () { banner.classList.add('is-shown'); });
    };

    if (consent === null) {
        showBanner();
    }

    // «Настройки cookie» в подвале: согласие должно отзываться так же
    // просто, как давалось, иначе это не согласие
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-cookie-settings]')) {
            e.preventDefault();
            showBanner();
        }
    });

    if (banner) {
        banner.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-cookie]');
            if (!btn) return;

            var choice = btn.dataset.cookie;

            localStorage.setItem(CONSENT_KEY, choice);
            if (choice === 'yes') loadMetrika();
            if (choice === 'no' && window.ym) { try { window.ym('destruct'); } catch (e) {} }

            // выбор фиксируется на сервере: дата, время и вариант согласия
            var payload = new FormData();
            payload.append('_token', csrf());
            payload.append('choice', choice);

            api('/api/soglasie-cookie', { method: 'POST', body: payload })
                .catch(function () { /* не записалось — на работу сайта это не ломает */ });

            banner.classList.remove('is-shown');
            setTimeout(function () { banner.hidden = true; }, 300);
        });
    }

    /* ------------------------------------------------ редактор текста */

    /*
     * Редактор поднимается отдельной функцией, а не сразу по всем найденным
     * полям: комментарии приезжают аяксом вместе со своей формой ответа,
     * и её редактор тоже нужно включить. Раньше этого не происходило —
     * текст оставался в видимой области, но в скрытое поле формы не попадал,
     * и сервер отвечал «комментарий не может быть пустым». После перезагрузки
     * страницы форма поднималась как обычно, поэтому баг и выглядел
     * непостоянным.
     */
    var setupEditor = function (wrap) {
        if (wrap.dataset.editorReady) return;

        wrap.dataset.editorReady = '1';

        var area = wrap.querySelector('.editor__area');
        var field = wrap.querySelector('textarea');
        var form = wrap.closest('form');

        /*
         * Enter делает перенос строки, а не новый блок.
         *
         * По умолчанию браузер оборачивает каждую строку в <div>, а пустую —
         * в <div><br></div>. Такие пустые обёртки чистилка разметки убирает
         * (иначе из чужих редакторов приезжают простыни пустых блоков),
         * и пустая строка пропадала. Человеку приходилось жать Shift+Enter.
         *
         * insertLineBreak вставляет <br> — он переживает чистку, и Enter
         * работает так, как все ожидают.
         */
        area.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' || e.shiftKey || e.ctrlKey || e.metaKey) return;

            // внутри списка Enter должен создавать новый пункт — не мешаем
            if (window.getSelection && document.queryCommandState('insertUnorderedList')) return;
            if (window.getSelection && document.queryCommandState('insertOrderedList')) return;

            e.preventDefault();
            document.execCommand('insertLineBreak');
            sync();
        });

        var sync = function () {
            var html = area.innerHTML.trim();

            // пустой редактор — пустое поле, а не «<br>» и прочий мусор
            if (html === '<br>' || html === '<div><br></div>' || area.textContent.trim() === '' && !area.querySelector('img')) {
                html = '';
            }

            field.value = html;
        };

        var exec = function (cmd, value) {
            area.focus();
            document.execCommand(cmd, false, value || null);
            sync();
        };

        wrap.querySelectorAll('[data-cmd]').forEach(function (btn) {
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); });

            btn.addEventListener('click', function () {
                var cmd = btn.dataset.cmd;

                if (cmd === 'bold' || cmd === 'italic' || cmd === 'underline') {
                    exec(cmd);
                } else if (cmd === 'strike') {
                    exec('strikeThrough');
                } else if (cmd === 'h2' || cmd === 'heading') {
                    // повторное нажатие возвращает обычный абзац — иначе
                    // из заголовка невозможно выйти, не удаляя строку
                    var tag = cmd === 'h2' ? 'H2' : 'H3';
                    exec('formatBlock', blockIs(tag) ? '<div>' : '<' + tag.toLowerCase() + '>');
                } else if (cmd === 'quote') {
                    exec('formatBlock', blockIs('BLOCKQUOTE') ? '<div>' : '<blockquote>');
                } else if (cmd === 'list') {
                    exec('insertUnorderedList');
                } else if (cmd === 'numlist') {
                    exec('insertOrderedList');
                } else if (cmd === 'undo') {
                    exec('undo');
                } else if (cmd === 'redo') {
                    exec('redo');
                } else if (cmd === 'unlink') {
                    exec('unlink');
                } else if (cmd === 'clear') {
                    exec('removeFormat');
                } else if (cmd === 'link') {
                    var url = prompt('Адрес ссылки', 'https://');
                    if (url && /^https?:\/\//i.test(url)) exec('createLink', url);
                } else if (cmd === 'image') {
                    pickImage(area, sync);
                } else if (cmd === 'emoji') {
                    togglePicker(wrap, area, sync);
                }

                updateState();
            });
        });

        // какой блочный тег сейчас под курсором
        var blockIs = function (tag) {
            var sel = window.getSelection();
            if (!sel || !sel.anchorNode) return false;

            var node = sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentNode;

            while (node && node !== area) {
                if (node.tagName === tag) return true;
                node = node.parentNode;
            }

            return false;
        };

        var updateState = function () {
            wrap.querySelectorAll('[data-cmd]').forEach(function (btn) {
                var map = { bold: 'bold', italic: 'italic', underline: 'underline', strike: 'strikeThrough',
                            list: 'insertUnorderedList', numlist: 'insertOrderedList' };
                var cmd = map[btn.dataset.cmd];

                if (cmd) {
                    try { btn.classList.toggle('is-active', document.queryCommandState(cmd)); } catch (e) {}

                    return;
                }

                var blocks = { h2: 'H2', heading: 'H3', quote: 'BLOCKQUOTE' };

                if (blocks[btn.dataset.cmd]) {
                    btn.classList.toggle('is-active', blockIs(blocks[btn.dataset.cmd]));
                }
            });
        };

        area.addEventListener('input', sync);
        area.addEventListener('blur', sync);
        area.addEventListener('keyup', updateState);
        area.addEventListener('mouseup', updateState);

        // вставка только текстом: иначе из Word приезжают чужие шрифты и цвета,
        // которые ломают оформление сайта
        area.addEventListener('paste', function (e) {
            e.preventDefault();
            var text = (e.clipboardData || window.clipboardData).getData('text/plain');
            document.execCommand('insertText', false, text);
            sync();
        });

        if (form) form.addEventListener('submit', sync);

        sync();
    };

    /** Поднять редакторы внутри узла — на странице целиком или в новом куске. */
    var initEditors = function (root) {
        (root || document).querySelectorAll('[data-editor]').forEach(setupEditor);
    };

    initEditors(document);

    function togglePicker(wrap, area, sync) {
        var picker = wrap.querySelector('.emoji-pick');
        if (!picker) return;

        picker.hidden = !picker.hidden;

        if (picker.dataset.ready) return;
        picker.dataset.ready = '1';

        picker.addEventListener('mousedown', function (e) { e.preventDefault(); });

        picker.addEventListener('click', function (e) {
            var item = e.target.closest('[data-emoji]');
            if (!item) return;

            area.focus();
            document.execCommand('insertText', false, item.dataset.emoji);
            sync();
        });

        // клик мимо панели закрывает её — иначе она висит и перекрывает текст
        document.addEventListener('click', function (e) {
            if (picker.hidden) return;
            if (picker.contains(e.target) || e.target.closest('[data-cmd="emoji"]')) return;

            picker.hidden = true;
        });
    }

    function pickImage(area, sync) {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';

        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;

            var data = new FormData();
            data.append('image', file);
            data.append('_token', csrf());

            area.focus();

            fetch(BASE_URL + '/zagruzka', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: data
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (res.error) { alert(res.error); return; }

                document.execCommand('insertHTML', false,
                    '<img src="' + res.url + '" loading="lazy"><br>');
                sync();
            }).catch(function () {
                alert('Не удалось загрузить картинку.');
            });
        });

        input.click();
    }

    /* ------------------------------------------- проверка промокода на дубль */

    document.querySelectorAll('[data-code-check]').forEach(function (input) {
        var result  = input.parentElement.querySelector('[data-code-check-result]');
        var exclude = input.dataset.codeCheckExclude || '';
        var timer   = null;

        if (!result) return;

        var render = function (data) {
            if (!data || !data.exists) {
                result.innerHTML = '';
                result.hidden = true;
                return;
            }

            result.hidden = false;
            result.innerHTML = 'Такой промокод уже опубликован: '
                + '<a href="' + data.url + '" target="_blank" rel="noopener">' + escapeHtml(data.title) + '</a>';
        };

        var escapeHtml = function (s) {
            var div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        };

        input.addEventListener('input', function () {
            clearTimeout(timer);
            var code = input.value.trim();

            if (code.length < 3) {
                render(null);
                return;
            }

            // не дёргаем сервер на каждое нажатие клавиши — ждём паузы в наборе
            timer = setTimeout(function () {
                var url = BASE_URL + '/proverka-promokoda?code=' + encodeURIComponent(code)
                    + (exclude ? '&exclude=' + exclude : '');

                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(render)
                    .catch(function () { /* тихо: проверка необязательна для отправки формы */ });
            }, 450);
        });
    });

    /* ------------------------------------------- карусель «Самое горячее» */

    document.querySelectorAll('[data-carousel]').forEach(function (track) {
        var wrap = track.closest('.hotline');
        if (!wrap) return;

        var arrows = wrap.querySelectorAll('[data-scroll]');

        var refresh = function () {
            var max = track.scrollWidth - track.clientWidth - 1;

            arrows.forEach(function (btn) {
                btn.disabled = btn.dataset.scroll === '-1'
                    ? track.scrollLeft <= 0
                    : track.scrollLeft >= max;
            });
        };

        arrows.forEach(function (btn) {
            btn.addEventListener('click', function () {
                // прокручиваем почти на всю видимую ширину, оставляя карточку
                // на виду как подсказку, что список продолжается
                var step = Math.max(200, track.clientWidth - 80);
                track.scrollBy({ left: step * parseInt(btn.dataset.scroll, 10), behavior: 'smooth' });
            });
        });

        track.addEventListener('scroll', function () {
            clearTimeout(track._t);
            track._t = setTimeout(refresh, 80);
        }, { passive: true });

        window.addEventListener('resize', refresh);
        refresh();
    });

    /* ==================================================================
       Обновление без перезагрузки
       ================================================================== */

    /*
     * Опрос подстраивается под человека: пока он смотрит на вкладку и что-то
     * делает — спрашиваем часто, ушёл — реже. Постоянный частый опрос грузил
     * бы сервер ради тех, кто отошёл за кофе.
     */
    var lastActivity = Date.now();

    ['click', 'keydown', 'scroll', 'mousemove', 'touchstart'].forEach(function (evt) {
        document.addEventListener(evt, function () { lastActivity = Date.now(); }, { passive: true });
    });

    var isActive = function () {
        return !document.hidden && (Date.now() - lastActivity) < 120000;
    };

    /** Запускает задачу с разной частотой в зависимости от активности. */
    var poll = function (task, fast, slow) {
        var run = function () {
            if (!document.hidden) task();

            setTimeout(run, isActive() ? fast : slow);
        };

        setTimeout(run, fast);

        // вернулись на вкладку — проверяем сразу, не дожидаясь таймера
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                lastActivity = Date.now();
                task();
            }
        });
    };

    var api = function (path, options) {
        options = options || {};
        options.headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest' }, options.headers || {});

        return fetch(BASE_URL + path, options).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok) throw new Error(data.error || 'Ошибка запроса');
                return data;
            });
        });
    };

    /*
     * Копия страницы, открытая по нажатию «Показать промокод»: раскрываем
     * тот код, ради которого её открыли, подводим к нему экран и убираем
     * метку из адреса — он должен совпадать с обычным адресом страницы.
     */
    (function () {
        var mark = '#promokod-';

        if (window.location.hash.indexOf(mark) !== 0) return;

        var slug = decodeURIComponent(window.location.hash.slice(mark.length));
        var boxes = document.querySelectorAll('.code-box[data-coupon]');
        var target = null;

        for (var i = 0; i < boxes.length; i++) {
            if (boxes[i].dataset.coupon.split('/').pop() === slug) {
                target = boxes[i];
                break;
            }
        }

        if (history.replaceState) {
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }

        if (!target) return;

        var value = target.querySelector('.code-box__value');
        var btn = target.querySelector('.code-box__btn');

        target.classList.add('is-open');

        if (value) value.textContent = target.dataset.code || '';
        if (btn) btn.textContent = 'Копировать';

        target.scrollIntoView({ block: 'center' });
    })();

    /* --------------------------------------------- подгрузка ленты */

    var feed = document.querySelector('[data-feed]');

    if (feed) {
        var moreBtn = document.querySelector('[data-feed-more]');
        var page = 1;
        var loading = false;
        var exhausted = feed.dataset.hasMore !== '1';

        var loadMore = function () {
            if (loading || exhausted) return;

            loading = true;
            if (moreBtn) moreBtn.textContent = 'Загружаем…';

            api('/api/lenta?' + feed.dataset.query + '&page=' + (page + 1)).then(function (data) {
                if (data.html) {
                    feed.insertAdjacentHTML('beforeend', data.html);
                    page = data.page;
                }

                exhausted = !data.has_more;

                if (moreBtn) {
                    moreBtn.textContent = 'Показать ещё';
                    moreBtn.hidden = exhausted;
                }
            }).catch(function () {
                if (moreBtn) moreBtn.textContent = 'Не получилось — попробовать ещё раз';
            }).then(function () {
                loading = false;
            });
        };

        if (moreBtn) {
            moreBtn.addEventListener('click', loadMore);
            moreBtn.hidden = exhausted;
        }

        /* ------------------------------ возврат на то же место в ленте

           Человек открывает скидку, жмёт «Назад» — и раньше попадал в начало
           ленты, потеряв всё, что пролистал. С телефона это особенно больно:
           заново листать никто не станет, страницу просто закрывают.

           Перед уходом запоминаем, сколько страниц догружено и возле какой
           карточки человек остановился. Место храним якорем, а не пикселями:
           картинки грузятся с разной скоростью и сдвигают ленту, а карточка
           остаётся той же.                                                */

        var STORE_KEY   = 'hh-feed:' + location.pathname + location.search;
        var STORE_TTL   = 30 * 60 * 1000;   // получасом позже это уже другая лента
        var MAX_RESTORE = 10;               // 150 карточек — дальше не тянем

        var scrollY = function () {
            return window.pageYOffset || document.documentElement.scrollTop || 0;
        };

        /** Карточка, на которой сейчас остановился взгляд, и её смещение. */
        var topCard = function () {
            var cards = feed.querySelectorAll('[data-thread]');

            for (var i = 0; i < cards.length; i++) {
                var box = cards[i].getBoundingClientRect();

                if (box.bottom > 0) {
                    return { slug: cards[i].getAttribute('data-thread'), offset: Math.round(box.top) };
                }
            }

            return null;
        };

        var saveState = function () {
            try {
                var anchor = topCard();

                sessionStorage.setItem(STORE_KEY, JSON.stringify({
                    page:   page,
                    y:      Math.round(scrollY()),
                    slug:   anchor ? anchor.slug : null,
                    offset: anchor ? anchor.offset : 0,
                    time:   Date.now()
                }));
            } catch (e) { /* приватный режим: просто не запомним */ }
        };

        var readState = function () {
            try {
                var raw = sessionStorage.getItem(STORE_KEY);

                if (!raw) return null;

                var saved = JSON.parse(raw);

                return (saved && Date.now() - saved.time < STORE_TTL) ? saved : null;
            } catch (e) { return null; }
        };

        // pagehide ловит и переход по ссылке, и жест «назад» на телефоне;
        // visibilitychange страхует Safari, где pagehide приходит не всегда
        window.addEventListener('pagehide', saveState);

        document.addEventListener('visibilitychange', function () {
            if (document.hidden) saveState();
        });

        var scrollToState = function (saved) {
            var apply = function () {
                var target = saved.y;

                if (saved.slug) {
                    // slug состоит из латиницы, цифр и дефисов — лишнее отбрасываем,
                    // чтобы селектор нельзя было сломать содержимым хранилища
                    var card = feed.querySelector('[data-thread="' + saved.slug.replace(/[^\w-]/g, '') + '"]');

                    if (card) {
                        target = Math.round(card.getBoundingClientRect().top + scrollY() - saved.offset);
                    }
                }

                window.scrollTo(0, Math.max(0, target));
            };

            // повторяем несколько раз: пока грузятся картинки, лента ещё едет
            apply();
            requestAnimationFrame(apply);
            setTimeout(apply, 150);
            setTimeout(apply, 500);
        };

        /*
         * Наблюдатель подключаем только после восстановления. Иначе он увидит
         * кнопку на короткой странице, догрузит вторую страницу сам, и она
         * приедет поверх восстановленной — карточки задвоятся.
         */
        var watchMore = function () {
            if ('IntersectionObserver' in window && moreBtn) {
                new IntersectionObserver(function (entries) {
                    if (entries[0].isIntersecting) loadMore();
                }, { rootMargin: '400px' }).observe(moreBtn);
            }
        };

        /*
         * Восстанавливаем только там, где человек ждёт прежнее место:
         * «Назад»/«Вперёд» и обновление страницы. При обычном переходе
         * (нажал на логотип, пришёл из поиска) лента открывается сверху,
         * иначе свежую ленту показывали бы с середины.
         */
        var shouldRestore = function () {
            try {
                var nav = performance.getEntriesByType('navigation')[0];

                if (nav) return nav.type === 'back_forward' || nav.type === 'reload';
            } catch (e) {}

            return !!(performance.navigation
                && (performance.navigation.type === 1 || performance.navigation.type === 2));
        };

        var restore = function () {
            var saved = shouldRestore() ? readState() : null;

            if (!saved) {
                watchMore();

                return;
            }

            var wanted = Math.min(saved.page || 1, MAX_RESTORE);

            if (wanted < 2 || exhausted) {
                scrollToState(saved);
                watchMore();

                return;
            }

            // все пролистанные страницы забираем одним запросом
            loading = true;

            if (moreBtn) {
                moreBtn.hidden = false;
                moreBtn.textContent = 'Возвращаем ленту…';
            }

            api('/api/lenta?' + feed.dataset.query + '&page=2&span=' + (wanted - 1)).then(function (data) {
                if (data.html) {
                    feed.insertAdjacentHTML('beforeend', data.html);
                    page = data.page;
                }

                exhausted = !data.has_more;
            }).catch(function () {
                // не вышло — останемся на первой странице, это не повод ломать ленту
            }).then(function () {
                loading = false;

                if (moreBtn) {
                    moreBtn.textContent = 'Показать ещё';
                    moreBtn.hidden = exhausted;
                }

                scrollToState(saved);
                watchMore();
            });
        };

        /*
         * Страницу вернул кеш «назад/вперёд»: разметка и все догруженные
         * карточки уже на месте, восстановить нужно только прокрутку.
         */
        window.addEventListener('pageshow', function (e) {
            if (!e.persisted) return;

            var saved = readState();

            if (saved) scrollToState(saved);
        });

        // прокруткой распоряжаемся сами — браузер иначе вернёт её до того,
        // как приедут догруженные карточки, и попадёт мимо
        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }

        restore();

        /* ------------------------------ сообщение о новых публикациях */

        var since = feed.dataset.since;
        var pill = document.querySelector('[data-feed-new]');

        if (since && pill) {
            var checkNew = function () {
                if (document.hidden) return;

                api('/api/lenta/novoe?' + feed.dataset.query + '&since=' + encodeURIComponent(since))
                    .then(function (data) {
                        // счётчик мог обнулиться: публикацию сняли с ленты
                        // или срок у неё вышел — плашку тогда убираем
                        if (!data.count) {
                            pill.hidden = true;

                            return;
                        }

                        pill.hidden = false;
                        pill.textContent = data.count + ' ' +
                            plural(data.count, 'новая публикация', 'новые публикации', 'новых публикаций')
                            + ' — показать';
                    })
                    .catch(function () { /* тихо: это фоновая проверка */ });
            };

            // Сами публикации не подставляем: лента прыгнула бы под курсором
            // у того, кто в этот момент читает. Показываем кнопку — решает человек.
            pill.addEventListener('click', function () { window.location.reload(); });

            poll(checkNew, 20000, 90000);
        }
    }

    function plural(n, one, few, many) {
        var n100 = Math.abs(n) % 100, n10 = n100 % 10;
        if (n100 > 10 && n100 < 20) return many;
        if (n10 > 1 && n10 < 5) return few;
        if (n10 === 1) return one;
        return many;
    }

    /* ------------------------------------------- комментарии без перезагрузки */

    var commentList = document.querySelector('[data-comments]');

    if (commentList) {
        var slug = commentList.dataset.slug;

        /**
         * Поставить комментарий на место — и не поставить его второй раз.
         *
         * Свою реплику скрипт добавлял сразу после отправки, а опрос через
         * несколько секунд приносил её же и вставлял ещё раз: автор видел
         * себя дважды, у остальных и после перезагрузки всё было верно.
         *
         * Сверяемся по id в разметке, а не по номеру последнего комментария:
         * запрос опроса мог уйти ещё до отправки и вернуть новый комментарий
         * при любом значении after — тогда никакой счётчик не спасёт.
         */
        var placeComment = function (item) {
            if (!item || !item.html) return false;

            var id = parseInt(item.id, 10) || 0;

            if (id && document.getElementById('comment-' + id)) return false;

            var parent = item.parent ? document.getElementById('comment-' + item.parent) : null;

            if (!parent) {
                commentList.insertAdjacentHTML('beforeend', item.html);
                initEditors(document.getElementById('comment-' + id));

                return true;
            }

            /*
             * Встаём после всей ветки родителя, а не после первого же соседа
             * с классом ответа: по классу нельзя отличить ответ этому
             * комментарию от ответа соседнему, и реплика иногда оказывалась
             * под чужим сообщением. Глубина отвечает на это однозначно —
             * ветка кончается там, где глубина стала не больше родительской.
             */
            var parentDepth = parseInt(parent.dataset.depth || '0', 10);
            var after = parent;

            while (after.nextElementSibling
                && after.nextElementSibling.classList.contains('comment')
                && parseInt(after.nextElementSibling.dataset.depth || '0', 10) > parentDepth) {
                after = after.nextElementSibling;
            }

            after.insertAdjacentHTML('afterend', item.html);
            initEditors(document.getElementById('comment-' + id));

            return true;
        };

        document.addEventListener('submit', function (e) {
            /*
             * Только форма создания: её адрес заканчивается на /kommentarii.
             * Раньше условие ловило и «/kommentarii/12/udalit», и «.../like» —
             * сервер отвечал обычной страницей, а скрипт ждал JSON и падал.
             */
            var form = e.target.closest('form[action$="/kommentarii"]');
            if (!form || form.dataset.noAjax) return;

            e.preventDefault();

            var btn = form.querySelector('button[type="submit"], .btn--primary');
            var prev = btn ? btn.textContent : '';
            if (btn) { btn.disabled = true; btn.textContent = 'Отправляем…'; }

            api(form.getAttribute('action').replace(BASE_URL, ''), {
                method: 'POST',
                body: new FormData(form)
            }).then(function (data) {
                placeComment(data);

                form.reset();

                var area = form.querySelector('.editor__area');
                if (area) area.innerHTML = '';

                var hidden = form.querySelector('textarea[name="body"]');
                if (hidden) hidden.value = '';

                var replyBox = form.closest('.reply-form');
                if (replyBox) replyBox.classList.remove('is-open');

                updateCommentCount(data.total);

                var added = document.getElementById('comment-' + data.id);
                if (added) added.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }).catch(function (err) {
                alert(err.message || 'Не удалось отправить комментарий.');
            }).then(function () {
                if (btn) { btn.disabled = false; btn.textContent = prev; }
            });
        });

        function updateCommentCount(total) {
            document.querySelectorAll('[data-comments-count]').forEach(function (el) {
                el.textContent = total;
            });
        }

        /* новые чужие комментарии подтягиваются сами */

        var lastId = parseInt(commentList.dataset.last || '0', 10);

        poll(function () {
            api('/api/kommentarii/' + encodeURIComponent(slug) + '?after=' + lastId).then(function (data) {
                if (!data.count) return;

                (data.items || []).forEach(placeComment);

                lastId = Math.max(lastId, parseInt(data.last, 10) || 0);
                updateCommentCount(data.total);
            }).catch(function () { /* тихо */ });
        }, 7000, 40000);
    }

    /* ------------------------------------------- удаление комментария */

    document.addEventListener('submit', function (e) {
        var form = e.target.closest('form[action*="/kommentarii/"][action$="/udalit"]');
        if (!form || form.dataset.noAjax) return;

        e.preventDefault();

        if (!confirm('Удалить комментарий?')) return;

        var box = form.closest('.comment');

        api(form.getAttribute('action').replace(BASE_URL, ''), {
            method: 'POST',
            body: new FormData(form)
        }).then(function (data) {
            /*
             * Сервер удаляет ветку целиком и возвращает все её номера.
             * Убираем со страницы каждый: ответы лежат соседними блоками,
             * и без этого они оставались бы висеть до перезагрузки.
             */
            var ids = data.ids && data.ids.length ? data.ids : [data.deleted];

            ids.forEach(function (id) {
                var node = document.getElementById('comment-' + id) || (id === data.deleted ? box : null);

                if (!node) return;

                node.style.transition = 'opacity .25s';
                node.style.opacity = '0';
                setTimeout(function () { node.remove(); }, 250);
            });

            document.querySelectorAll('[data-comments-count]').forEach(function (el) {
                el.textContent = data.total;
            });
        }).catch(function () {
            form.dataset.noAjax = '1';
            form.submit();
        });
    });

    /* ---------------------------------------------------- галерея */

    /*
     * Миниатюра меняет крупный снимок, а не открывает его: человек
     * пролистывает товар, оставаясь на странице. Полноэкранный просмотр
     * по-прежнему по нажатию на сам снимок.
     */
    document.addEventListener('click', function (e) {
        var thumb = e.target.closest('.gallery__thumb');

        if (!thumb) return;

        var gallery = thumb.closest('[data-gallery]');
        var main = gallery.querySelector('.gallery__main img');

        if (!main) return;

        main.src = thumb.dataset.shot;

        gallery.querySelectorAll('.gallery__thumb').forEach(function (item) {
            item.classList.toggle('is-active', item === thumb);
        });
    });

    /* ------------------------------------------ просмотр картинок */

    /*
     * Нажатие на картинку открывает её во весь экран. Если рядом есть другие
     * снимки — галерея товара или картинки в том же тексте, — между ними
     * можно ходить, не закрывая просмотр, как у Озона: стрелки по бокам,
     * клавиши ←/→, свайп на телефоне, миниатюры в ленте слева (внизу на
     * телефоне). Закрывается крестиком, Esc или нажатием на фон.
     *
     * Ловим только картинки внутри текста публикаций и комментариев и
     * крупный снимок галереи — аватары, логотипы и иконки открывать незачем.
     */
    var lightbox = null;
    var lbShots = [];
    var lbIndex = 0;
    var lbReturn = null;

    var ARROW = function (d) {
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="' + d + '"/></svg>';
    };

    var absUrl = function (src) {
        try { return new URL(src, window.location.href).href; } catch (e) { return src; }
    };

    var closeLightbox = function () {
        if (!lightbox || lightbox.hidden) return;

        lightbox.classList.remove('is-open');
        document.body.classList.remove('is-panel-open');

        setTimeout(function () {
            if (lightbox && !lightbox.classList.contains('is-open')) lightbox.hidden = true;
        }, 200);

        if (lbReturn && lbReturn.focus) lbReturn.focus({ preventScroll: true });
    };

    var showShot = function (i) {
        if (!lbShots.length) return;

        lbIndex = (i + lbShots.length) % lbShots.length;
        lightbox.querySelector('.lightbox__img').src = lbShots[lbIndex];

        var count = lightbox.querySelector('.lightbox__count');
        if (count) count.textContent = (lbIndex + 1) + ' из ' + lbShots.length;

        lightbox.querySelectorAll('.lightbox__thumb').forEach(function (thumb, k) {
            var active = k === lbIndex;
            thumb.classList.toggle('is-active', active);
            if (active && thumb.scrollIntoView) thumb.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        });

        // соседние снимки грузим заранее — листание без задержки
        [lbIndex + 1, lbIndex - 1].forEach(function (k) {
            var next = new Image();
            next.src = lbShots[(k + lbShots.length) % lbShots.length];
        });
    };

    var buildLightbox = function () {
        lightbox = document.createElement('div');
        lightbox.className = 'lightbox';
        lightbox.hidden = true;
        lightbox.setAttribute('role', 'dialog');
        lightbox.setAttribute('aria-modal', 'true');
        lightbox.setAttribute('aria-label', 'Просмотр фото');
        lightbox.innerHTML = '<div class="lightbox__rail"></div>'
            + '<div class="lightbox__stage">'
            + '<button type="button" class="lightbox__nav lightbox__nav--prev" aria-label="Предыдущее фото">' + ARROW('M15 18l-6-6 6-6') + '</button>'
            + '<img class="lightbox__img" alt="">'
            + '<button type="button" class="lightbox__nav lightbox__nav--next" aria-label="Следующее фото">' + ARROW('M9 6l6 6-6 6') + '</button>'
            + '</div>'
            + '<button type="button" class="lightbox__close" aria-label="Закрыть">&times;</button>';
        document.body.appendChild(lightbox);

        lightbox.addEventListener('click', function (e) {
            if (e.target.closest('.lightbox__nav--prev')) return showShot(lbIndex - 1);
            if (e.target.closest('.lightbox__nav--next')) return showShot(lbIndex + 1);

            var thumb = e.target.closest('.lightbox__thumb');
            if (thumb) return showShot(parseInt(thumb.dataset.index, 10));

            // сам снимок и лента миниатюр просмотр не закрывают — только фон и крестик
            if (e.target.closest('.lightbox__img') || e.target.closest('.lightbox__rail')) return;

            closeLightbox();
        });

        // свайп влево-вправо — следующий или предыдущий снимок
        var stage = lightbox.querySelector('.lightbox__stage');
        var startX = null;
        var startY = null;

        stage.addEventListener('touchstart', function (e) {
            if (e.touches.length !== 1) { startX = null; return; }
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
        }, { passive: true });

        stage.addEventListener('touchend', function (e) {
            if (startX === null) return;

            var dx = e.changedTouches[0].clientX - startX;
            var dy = e.changedTouches[0].clientY - startY;
            startX = null;

            if (lbShots.length > 1 && Math.abs(dx) > 40 && Math.abs(dx) > Math.abs(dy) * 1.5) {
                showShot(lbIndex + (dx < 0 ? 1 : -1));
            }
        });
    };

    var openLightbox = function (shots, index, from) {
        if (!lightbox) buildLightbox();

        lbShots = shots;
        lbReturn = from || null;

        var many = shots.length > 1;
        var rail = lightbox.querySelector('.lightbox__rail');

        rail.innerHTML = '';
        rail.hidden = !many;

        if (many) {
            var count = document.createElement('div');
            count.className = 'lightbox__count';
            rail.appendChild(count);

            shots.forEach(function (src, k) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'lightbox__thumb';
                btn.dataset.index = String(k);
                btn.setAttribute('aria-label', 'Фото ' + (k + 1));

                var img = document.createElement('img');
                img.src = src;
                img.alt = '';
                img.loading = 'lazy';

                btn.appendChild(img);
                rail.appendChild(btn);
            });
        }

        lightbox.querySelectorAll('.lightbox__nav').forEach(function (btn) { btn.hidden = !many; });

        lightbox.hidden = false;
        showShot(index);

        // класс вешаем следующим кадром, иначе переход не проигрывается
        requestAnimationFrame(function () {
            lightbox.classList.add('is-open');
            document.body.classList.add('is-panel-open');
            lightbox.querySelector('.lightbox__close').focus({ preventScroll: true });
        });
    };

    document.addEventListener('click', function (e) {
        var img = e.target.closest('.prose img, .comment__body img, .thread__body img, .gallery__main img');

        if (!img || !img.src || (lightbox && lightbox.contains(img))) return;

        e.preventDefault();

        var current = absUrl(img.currentSrc || img.src);
        var shots = [];
        var gallery = img.closest('[data-gallery]');

        if (gallery) {
            gallery.querySelectorAll('.gallery__thumb[data-shot]').forEach(function (thumb) {
                shots.push(absUrl(thumb.dataset.shot));
            });
        } else {
            var box = img.closest('.prose, .comment__body, .thread__body');

            if (box) {
                box.querySelectorAll('img').forEach(function (item) {
                    if (item.src) shots.push(absUrl(item.currentSrc || item.src));
                });
            }
        }

        if (!shots.length) shots = [current];

        var index = shots.indexOf(current);

        openLightbox(shots, index === -1 ? 0 : index, img);
    });

    document.addEventListener('keydown', function (e) {
        if (!lightbox || lightbox.hidden) return;

        if (e.key === 'Escape') closeLightbox();
        if (e.key === 'ArrowLeft' && lbShots.length > 1) { e.preventDefault(); showShot(lbIndex - 1); }
        if (e.key === 'ArrowRight' && lbShots.length > 1) { e.preventDefault(); showShot(lbIndex + 1); }
    });

    /* --------------------------------- «Проверено»: когда проверяли */

    /*
     * Подсказка у знака вопроса. На компьютере открывается наведением (CSS),
     * на телефоне — нажатием. Перед показом облачко сдвигается так, чтобы
     * не уехать за край экрана: метка часто стоит у левого края карточки.
     */
    var placeTip = function (btn) {
        var bubble = btn.querySelector('.vtip__bubble');
        if (!bubble) return;

        bubble.style.setProperty('--vtip-shift', '0px');

        var box = bubble.getBoundingClientRect();
        var pad = 12;
        var shift = 0;

        if (box.left < pad) shift = pad - box.left;
        else if (box.right > window.innerWidth - pad) shift = window.innerWidth - pad - box.right;

        bubble.style.setProperty('--vtip-shift', Math.round(shift) + 'px');
    };

    var closeTips = function (except) {
        document.querySelectorAll('.vtip.is-open').forEach(function (btn) {
            if (btn === except) return;
            btn.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
        });
    };

    document.addEventListener('mouseover', function (e) {
        var btn = e.target.closest && e.target.closest('[data-vtip]');
        if (btn) placeTip(btn);
    });

    // фаза перехвата: нажатие на «?» не должно открывать саму публикацию
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-vtip]');

        closeTips(btn);

        if (!btn) return;

        e.preventDefault();
        e.stopPropagation();

        placeTip(btn);

        var open = !btn.classList.contains('is-open');
        btn.classList.toggle('is-open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeTips(null);
    });

    window.addEventListener('scroll', function () { closeTips(null); }, { passive: true });

    /* ----------------------------------------- заявка на вывод: рубли */

    // сколько рублей выйдет по текущему курсу — пересчитываем по мере ввода
    var rubOut = document.querySelector('[data-rub-out]');
    var rubAmount = document.getElementById('amount');

    if (rubOut && rubAmount) {
        rubAmount.addEventListener('input', function () {
            var value = parseFloat(String(rubAmount.value).replace(',', '.')) || 0;
            var rub = value * (parseFloat(rubOut.dataset.rate) || 0);

            rubOut.textContent = rub.toFixed(2).replace('.', ',');
        });
    }

    /* ---------------------------------------------- цели в Метрике */

    /*
     * Переход в магазин — единственное действие на сайте, которое приносит
     * деньги. В базе он считается давно, но Метрика о нём не знала, и по
     * отчётам было не понять, какой источник трафика приводит покупателей.
     *
     * Цель отправляем до ухода со страницы: счётчик успевает отправить
     * запрос, потому что использует sendBeacon.
     */
    var reachGoal = function (goal, params) {
        var id = window.PROMIKI_METRIKA;

        if (!id || typeof window.ym !== 'function') return;

        try { window.ym(id, 'reachGoal', goal, params || {}); } catch (err) {}
    };

    document.addEventListener('click', function (e) {
        var link = e.target.closest('a[href*="/go/"]');

        if (!link) return;

        reachGoal('go_to_shop', { slug: link.getAttribute('href').split('/go/').pop() });
    });

    /* ------------------------------------------ проверка размера файла */

    /*
     * Ловим большой файл на месте. Иначе браузер отправляет запрос, сервер
     * отбрасывает его целиком вместе с введёнными данными, и человек получает
     * невнятную ошибку вместо простого «уменьшите картинку».
     */
    document.addEventListener('change', function (e) {
        var input = e.target.closest('input[type="file"]');

        // поле выбора файла, созданное скриптом и уже убранное со страницы
        // (так делает загрузка фото в блочном редакторе), здесь не проверяем:
        // подсказку повесить некуда, а размер там проверяет сам редактор
        if (!input || !input.files || !input.files.length || !input.parentNode) return;

        var limit = parseInt(input.dataset.maxSize || window.PROMIKI_UPLOAD_LIMIT || '0', 10);

        if (!limit) return;

        var tooBig = Array.prototype.filter.call(input.files, function (file) {
            return file.size > limit;
        });

        var note = input.parentNode.querySelector('[data-size-error]');

        if (!tooBig.length) {
            if (note) note.remove();

            return;
        }

        input.value = '';

        if (!note) {
            note = document.createElement('div');
            note.className = 'hint';
            note.setAttribute('data-size-error', '');
            note.style.color = '#d94a3d';
            input.parentNode.insertBefore(note, input.nextSibling);
        }

        note.textContent = 'Файл больше ' + Math.round(limit / 1024 / 1024 * 10) / 10
            + ' МБ — сервер такой не примет. Уменьшите изображение и выберите заново.';
    });

    /* --------------------------------------- сворачивание веток */

    /*
     * Ответы лежат соседними блоками с отметкой глубины, а не внутри
     * родителя. Поэтому ветка — это все следующие соседи, у которых глубина
     * больше; как только встретили равную или меньшую, ветка кончилась.
     */
    document.addEventListener('click', function (e) {
        var toggle = e.target.closest('[data-collapse]');
        if (!toggle) return;

        var box = toggle.closest('.comment');
        var depth = parseInt(box.dataset.depth || '0', 10);
        var folded = box.classList.toggle('is-folded');
        var node = box.nextElementSibling;
        var hidden = 0;

        while (node && node.classList.contains('comment')) {
            if (parseInt(node.dataset.depth || '0', 10) <= depth) break;

            node.hidden = folded;
            hidden++;
            node = node.nextElementSibling;
        }

        toggle.innerHTML = folded ? '+' : '&minus;';
        toggle.title = folded ? 'Развернуть ветку' : 'Свернуть ветку';
        toggle.setAttribute('aria-label', toggle.title);

        var label = box.querySelector('.comment__folded');

        if (label) {
            label.hidden = !folded;
            label.textContent = folded
                ? hidden + ' ' + (hidden % 10 === 1 && hidden % 100 !== 11 ? 'ответ'
                    : (hidden % 10 >= 2 && hidden % 10 <= 4 && (hidden % 100 < 10 || hidden % 100 >= 20) ? 'ответа' : 'ответов'))
                : '';
        }
    });

    /* ------------------------------------- лайки, закладки, подписки */

    document.addEventListener('submit', function (e) {
        var form = e.target.closest('form[action*="/like"], form[action*="/zakladka"], form[action*="/podpiska"]');
        if (!form) return;

        e.preventDefault();

        var btn = form.querySelector('button');

        api(form.getAttribute('action').replace(BASE_URL, ''), {
            method: 'POST',
            body: new FormData(form)
        }).then(function (data) {
            if (!btn) return;

            if ('likes' in data) {
                var icon = btn.querySelector('svg');
                btn.innerHTML = (icon ? icon.outerHTML : '') + ' ' + data.likes;
                btn.style.color = data.liked ? 'var(--brand)' : '';
            }

            if ('saved' in data) {
                btn.classList.toggle('is-active', data.saved);
                var label = btn.querySelector('[data-label]') || btn;
                label.textContent = data.saved ? 'В закладках' : 'В закладки';
            }

            if ('following' in data) {
                btn.classList.toggle('btn--primary', !data.following);
                btn.classList.toggle('btn--ghost', data.following);

                var text = btn.querySelector('span:last-child') || btn;
                text.textContent = data.following ? 'Вы подписаны' : 'Подписаться';

                document.querySelectorAll('[data-followers]').forEach(function (el) {
                    el.textContent = data.followers;
                });
            }
        }).catch(function () {
            form.dataset.noAjax = '1';
            form.submit();
        });
    });

    /* ---------------------------------------- счётчик уведомлений */

    var bell = document.querySelector('[data-unread]');

    if (bell) {
        poll(function () {
            api('/api/uvedomleniya').then(function (data) {
                var badge = bell.querySelector('.bell__count');

                if (data.count > 0) {
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'bell__dot bell__count';
                        bell.appendChild(badge);
                    }

                    badge.textContent = data.count > 99 ? '99+' : data.count;
                } else if (badge) {
                    badge.remove();
                }
            }).catch(function () { /* тихо */ });
        }, 20000, 90000);
    }

    /* ----------------------------- прокрутка к полю поиска на телефоне */

    var search = document.querySelector('.search input');

    if (search) {
        search.addEventListener('focus', function () {
            if (window.innerWidth <= 860) {
                setTimeout(function () {
                    search.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }, 250);
            }
        });
    }
})();
