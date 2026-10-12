/**
 * Блочный редактор публикаций — Editor.js, тот же редактор, что у vc.ru.
 *
 * Каждый абзац, подзаголовок, список, цитата и фото — отдельный блок.
 * Новый блок добавляется кнопкой «+» слева или символом «/» в пустой строке,
 * выделение текста открывает панель с жирным, курсивом и ссылкой.
 *
 * Сохранение: при каждой правке блоки пишутся JSON-ом в скрытое поле
 * body_json, а перед отправкой формы — ещё раз, чтобы не потерять
 * последние секунды набора. HTML из этих блоков собирает сервер.
 *
 * Сам Editor.js и плагины лежат в js/vendor/editorjs.bundle.js — свой файл,
 * без CDN: сторонние CDN из России открываются через раз.
 */
(function () {
    'use strict';

    var editors = document.querySelectorAll('[data-blocks-editor]');

    if (!editors.length) return;

    if (!window.EditorJS) {
        editors.forEach(function (wrap) {
            var failed = wrap.querySelector('[data-bed-failed]');
            if (failed) failed.hidden = false;
        });

        return;
    }

    var token = function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    };

    /* --------------------------------------------------------- иконки */

    var svg = function (body) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24">' + body + '</svg>';
    };

    var ICON = {
        h2: svg('<path stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M5 7v10M13 7v10M5 12h8"/>'
            + '<path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M16.2 11.2c.3-.8 1-1.2 1.8-1.2 1 0 1.8.7 1.8 1.6 0 1.8-3.6 2.6-3.6 5.4h3.8"/>'),
        h3: svg('<path stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M5 7v10M13 7v10M5 12h8"/>'
            + '<path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="M16.3 10.4c.4-.3.9-.4 1.5-.4 1 0 1.8.6 1.8 1.5s-.8 1.5-1.8 1.5c1.1 0 2 .7 2 1.7 0 1-.9 1.7-2 1.7-.6 0-1.2-.2-1.6-.6"/>'),
        underline: svg('<path stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M8 5v6a4 4 0 0 0 8 0V5M7 19h10"/>'),
        strike: svg('<path stroke="currentColor" stroke-linecap="round" stroke-width="2" d="M5 12h14M16 7.5C15.4 6 13.9 5 12 5c-2.2 0-4 1.3-4 3 0 1 .5 1.8 1.5 2.3M8 16.5C8.6 18 10.1 19 12 19c2.2 0 4-1.3 4-3 0-.5-.1-.9-.3-1.3"/>')
    };

    /* -------------------------------------------------------- русский */

    var I18N = {
        messages: {
            ui: {
                blockTunes: { toggler: { 'Click to tune': 'Настроить блок', 'or drag to move': 'или перетащить' } },
                inlineToolbar: { converter: { 'Convert to': 'Превратить в' } },
                toolbar: { toolbox: { Add: 'Добавить блок' } },
                popover: { Filter: 'Поиск', 'Nothing found': 'Ничего не найдено', 'Convert to': 'Превратить в' }
            },
            toolNames: {
                Text: 'Текст',
                Heading: 'Подзаголовок',
                List: 'Список',
                'Unordered List': 'Список',
                'Ordered List': 'Нумерованный список',
                Quote: 'Цитата',
                Delimiter: 'Разделитель',
                Image: 'Фото',
                Link: 'Ссылка',
                Bold: 'Жирный',
                Italic: 'Курсив'
            },
            tools: {
                link: { 'Add a link': 'Вставьте ссылку и нажмите Enter' },
                stub: { 'The block can not be displayed correctly.': 'Этот блок не получается показать.' },
                header: { 'Heading 2': 'Подзаголовок H2', 'Heading 3': 'Подзаголовок H3' },
                list: {
                    Unordered: 'Маркированный',
                    Ordered: 'Нумерованный',
                    Checklist: 'Чек-лист',
                    'Start with': 'Начать с номера',
                    'Counter type': 'Вид нумерации'
                },
                image: {
                    Caption: 'Подпись',
                    'Select an Image': 'Выбрать фото',
                    'With border': 'С рамкой',
                    'Stretch image': 'Во всю ширину',
                    'With background': 'С фоном',
                    'Couldn’t upload image. Please try another.': 'Не удалось загрузить фото. Подойдёт PNG, JPG, GIF или WEBP до 4 МБ.'
                },
                quote: { 'Align Left': 'По левому краю', 'Align Center': 'По центру' }
            },
            blockTunes: {
                delete: { Delete: 'Удалить', 'Click to delete': 'Точно удалить?' },
                moveUp: { 'Move up': 'Выше' },
                moveDown: { 'Move down': 'Ниже' }
            }
        }
    };

    /* ---------------------------------------- строчные инструменты */

    /*
     * Подчёркивание и зачёркивание — через execCommand, как жирный и курсив
     * в самом Editor.js. Отдельных пакетов ради двух кнопок не тянем.
     */
    var inlineTool = function (command, title, icon, tags, shortcut) {
        var Tool = function () {};

        Tool.isInline = true;
        Tool.title = title;
        Tool.sanitize = tags;
        Tool.shortcut = shortcut;

        Tool.prototype.render = function () {
            return {
                icon: icon,
                name: command,
                onActivate: function () { document.execCommand(command); },
                isActive: function () {
                    try { return document.queryCommandState(command); } catch (e) { return false; }
                }
            };
        };

        return Tool;
    };

    var Underline = inlineTool('underline', 'Подчёркнутый', ICON.underline, { u: {} }, 'CMD+U');
    var Strike = inlineTool('strikeThrough', 'Зачёркнутый', ICON.strike, { s: {}, strike: {} }, 'CMD+SHIFT+X');

    /*
     * Список без чек-листа и без выбора вида нумерации: на сайте такие
     * пункты всё равно выводятся обычным списком, и настройка только путала бы.
     */
    var List = window.EditorjsList;

    if (List) {
        List = class extends window.EditorjsList {
            renderSettings() {
                var hide = [this.api.i18n.t('Checklist'), this.api.i18n.t('Counter type')];

                return super.renderSettings().filter(function (item) {
                    return hide.indexOf(item.label) === -1;
                });
            }
        };
    }

    /* ------------------------------------------------ загрузка фото */

    var uploader = function (wrap) {
        var limit = parseInt(wrap.dataset.uploadLimit, 10) || 4 * 1024 * 1024;

        return {
            uploadByFile: function (file) {
                if (file.size > limit) {
                    return Promise.resolve({ success: 0 });
                }

                var data = new FormData();
                data.append('image', file);
                data.append('_token', token());

                return fetch(wrap.dataset.uploadUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: data
                }).then(function (r) {
                    return r.json();
                }).then(function (res) {
                    return res && res.url
                        ? { success: 1, file: { url: res.url } }
                        : { success: 0, message: res && res.error };
                }).catch(function () {
                    return { success: 0 };
                });
            },

            // чужие адреса картинок сайт не принимает: они выдают читателей
            // владельцу картинки и пропадают вместе с чужим сайтом
            uploadByUrl: function () {
                return Promise.resolve({ success: 0 });
            }
        };
    };

    /* ------------------------------------------------- набор блоков */

    var tools = function (wrap) {
        var set = {
            list: List && {
                class: List,
                inlineToolbar: true,
                config: { defaultStyle: 'unordered', maxLevel: 2 },
                toolbox: [
                    { title: 'Список', data: { style: 'unordered' } },
                    { title: 'Нумерованный список', data: { style: 'ordered' } }
                ]
            },
            quote: window.Quote && {
                class: window.Quote,
                inlineToolbar: true,
                config: { quotePlaceholder: 'Текст цитаты', captionPlaceholder: 'Автор или источник' }
            },
            image: window.ImageTool && {
                class: window.ImageTool,
                config: {
                    types: 'image/png, image/jpeg, image/gif, image/webp',
                    captionPlaceholder: 'Подпись к фото',
                    buttonContent: 'Выбрать фото',
                    features: { border: false, background: false, stretch: false, caption: true },
                    uploader: uploader(wrap)
                }
            }
        };

        if (wrap.dataset.preset !== 'offer') {
            set.header = window.Header && {
                class: window.Header,
                inlineToolbar: ['link', 'italic'],
                config: { levels: [2, 3], defaultLevel: 2, placeholder: 'Подзаголовок' },
                toolbox: [
                    { title: 'Подзаголовок H2', icon: ICON.h2, data: { level: 2 } },
                    { title: 'Подзаголовок H3', icon: ICON.h3, data: { level: 3 } }
                ]
            };

            set.delimiter = window.Delimiter && { class: window.Delimiter };
        }

        set.underline = { class: Underline };
        set.strike = { class: Strike };

        // порядок в меню «+» — как на vc.ru: сначала заголовки, потом остальное
        var order = ['header', 'image', 'list', 'quote', 'delimiter', 'underline', 'strike'];
        var out = {};

        order.forEach(function (name) {
            if (set[name]) out[name] = set[name];
        });

        return out;
    };

    /* ------------------------------------------------- один редактор */

    var setup = function (wrap) {
        if (wrap.dataset.bedReady) return;
        wrap.dataset.bedReady = '1';

        var holder = wrap.querySelector('[data-bed-holder]');
        var field = wrap.querySelector('[data-bed-field]');
        var title = wrap.querySelector('[data-bed-title]');
        var form = wrap.closest('form');

        var initial = { blocks: [] };

        try {
            var parsed = JSON.parse(field.value || '{}');
            if (parsed && Array.isArray(parsed.blocks)) initial = { blocks: parsed.blocks };
        } catch (e) { /* поле испорчено — начинаем с чистого листа */ }

        var editor;
        var saving = null;

        var save = function () {
            if (!editor || !editor.save) return Promise.resolve();

            saving = editor.save().then(function (out) {
                field.value = JSON.stringify({ blocks: out.blocks });
            }).catch(function () {
                // сохранить не вышло — в поле остаётся прошлая версия текста
            });

            return saving;
        };

        var timer = null;

        editor = new window.EditorJS({
            holder: holder,
            data: initial,
            tools: tools(wrap),
            inlineToolbar: ['bold', 'italic', 'underline', 'strike', 'link'],
            placeholder: wrap.dataset.placeholder || 'Начните писать…',
            minHeight: 60,
            i18n: I18N,
            logLevel: 'ERROR',

            onReady: function () {
                wrap.classList.add('is-ready');
            },

            onChange: function () {
                clearTimeout(timer);
                timer = setTimeout(save, 300);
            }
        });

        // для отладки из консоли: document.querySelector('.bed').blocksEditor.save()
        wrap.blocksEditor = editor;

        /*
         * Перед отправкой — последнее сохранение. editor.save() асинхронный,
         * поэтому отправку придерживаем и повторяем через requestSubmit:
         * так браузер ещё раз проверит обязательные поля и галочки согласий.
         */
        if (form) {
            form.addEventListener('submit', function (e) {
                // кнопки внутри самого редактора форму не отправляют
                if (e.submitter && holder.contains(e.submitter)) {
                    e.preventDefault();
                    return;
                }

                if (form.dataset.bedSaved === '1') {
                    form.dataset.bedSaved = '';
                    return;
                }

                e.preventDefault();

                var submitter = e.submitter;

                clearTimeout(timer);

                /*
                 * Повторная отправка — строго после того, как браузер закончит
                 * рассылать текущее событие submit. Пока оно идёт, requestSubmit
                 * молча игнорируется (так по стандарту), а сохранение редактора
                 * иногда успевает завершиться раньше — и форма не уходила вовсе.
                 */
                save().then(function () {
                    setTimeout(function () {
                        form.dataset.bedSaved = '1';

                        if (form.requestSubmit) {
                            form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
                        } else {
                            form.submit();
                        }

                        // если отправку остановила проверка полей — следующая
                        // попытка снова начнётся с сохранения
                        setTimeout(function () { form.dataset.bedSaved = ''; }, 0);
                    }, 0);
                });
            });
        }

        /*
         * Смайлики. Вставляем туда, где последним стоял курсор: в заголовок
         * или в блок текста. Кнопки не забирают фокус (mousedown отменён),
         * поэтому выделение в тексте не теряется. Курсора не было вовсе —
         * смайлик уходит в конец последнего абзаца или новым абзацем.
         */
        var emojiBtn = wrap.querySelector('[data-bed-emoji]');
        var emojiPick = wrap.querySelector('[data-bed-emoji-pick]');
        var lastRange = null;
        var lastInTitle = false;

        document.addEventListener('selectionchange', function () {
            var sel = document.getSelection();

            if (sel && sel.rangeCount && sel.anchorNode && holder.contains(sel.anchorNode)) {
                lastRange = sel.getRangeAt(0).cloneRange();
                lastInTitle = false;
            }
        });

        if (title) {
            title.addEventListener('focus', function () { lastInTitle = true; });
        }

        var editableOf = function (node) {
            var el = node && (node.nodeType === 1 ? node : node.parentElement);
            return el ? el.closest('[contenteditable="true"]') : null;
        };

        var insertEmoji = function (ch) {
            if (lastInTitle && title) {
                var start = title.selectionStart == null ? title.value.length : title.selectionStart;
                var end = title.selectionEnd == null ? start : title.selectionEnd;

                title.focus();
                title.setRangeText(ch, start, end, 'end');
                title.dispatchEvent(new Event('input', { bubbles: true }));
                return;
            }

            var target = lastRange && document.contains(lastRange.startContainer) ? editableOf(lastRange.startContainer) : null;

            if (target && holder.contains(target)) {
                target.focus();

                var sel = document.getSelection();
                sel.removeAllRanges();
                sel.addRange(lastRange);

                document.execCommand('insertText', false, ch);
                return;
            }

            editor.isReady.then(function () {
                editor.caret.setToLastBlock('end');

                var active = document.activeElement;

                if (active && active.isContentEditable && holder.contains(active)) {
                    document.execCommand('insertText', false, ch);
                } else {
                    editor.blocks.insert('paragraph', { text: ch });
                }
            });
        };

        var togglePick = function (open) {
            emojiPick.hidden = !open;
            emojiBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        };

        if (emojiBtn && emojiPick) {
            [emojiBtn, emojiPick].forEach(function (el) {
                el.addEventListener('mousedown', function (e) { e.preventDefault(); });
            });

            emojiBtn.addEventListener('click', function () { togglePick(emojiPick.hidden); });

            emojiPick.addEventListener('click', function (e) {
                var item = e.target.closest('[data-emoji]');
                if (!item) return;

                insertEmoji(item.dataset.emoji);
                togglePick(false);
            });

            document.addEventListener('click', function (e) {
                if (!emojiPick.hidden && !emojiPick.contains(e.target) && !emojiBtn.contains(e.target)) togglePick(false);
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && !emojiPick.hidden) togglePick(false);
            });
        }

        /*
         * Заголовок как на vc.ru: крупный, без рамки, растёт по высоте вместе
         * с текстом. Перевод строки ему не нужен — Enter уводит к тексту.
         */
        if (title) {
            var fit = function () {
                title.style.height = 'auto';
                title.style.height = title.scrollHeight + 'px';
            };

            title.addEventListener('input', function () {
                if (/[\r\n]/.test(title.value)) {
                    title.value = title.value.replace(/[\r\n]+/g, ' ');
                }

                fit();
            });

            title.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;

                e.preventDefault();

                if (editor && editor.caret) {
                    editor.isReady.then(function () { editor.caret.setToFirstBlock('end'); });
                }
            });

            window.addEventListener('resize', fit);
            fit();
        }
    };

    editors.forEach(setup);
})();
