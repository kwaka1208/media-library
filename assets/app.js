/* ---------------------------------------------------------------
   Media Library : ライトボックス・ツールバー・操作モーダル
   --------------------------------------------------------------- */
(function () {
    'use strict';

    // ---- ツールバー：入力に応じて自動で絞り込み・並び替え ----------

    var form = document.querySelector('.toolbar');

    if (form) {
        var searchInput = form.querySelector('input[type="search"]');
        var timer = null;

        form.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
            select.addEventListener('change', function () {
                form.submit();
            });
        });

        if (searchInput) {
            // 入力が止まってから送信する（1文字ごとのリロードを避ける）
            searchInput.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    sessionStorage.setItem('pv-focus-search', '1');
                    form.submit();
                }, 400);
            });

            // Enterでの送信時はタイマーを止めて二重送信を防ぐ
            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    window.clearTimeout(timer);
                    sessionStorage.setItem('pv-focus-search', '1');
                }
            });

            // 再読み込み後もそのまま入力を続けられるようにする
            if (sessionStorage.getItem('pv-focus-search') === '1') {
                sessionStorage.removeItem('pv-focus-search');
                searchInput.focus();
                var value = searchInput.value;
                searchInput.value = '';
                searchInput.value = value;
            }
        }
    }

    // ---- 操作用のモーダル ------------------------------------------

    var openedModal = null;
    var modalOpener = null;

    function openModal(modal, opener) {
        modalOpener = opener || null;
        modal.hidden = false;
        openedModal = modal;
        document.body.style.overflow = 'hidden';
    }

    // 書きかけが消えると困る画面には、data-modal-keep を付けておく。
    // 閉じるのは「キャンセル」を押したときと、保存して画面が切り替わるときだけ。
    // 外側のクリックや Esc など、ほかのきっかけでは閉じない。
    //
    // 判断を closeModal() の中で行うので、どこから呼ばれても取りこぼさない。
    // 明示的に閉じたいときは closeModal(true) を使う。
    function closeModal(force) {
        if (!openedModal) {
            return;
        }

        if (!force && openedModal.hasAttribute('data-modal-keep')) {
            return;
        }

        openedModal.hidden = true;
        openedModal = null;
        document.body.style.overflow = '';

        // 押したボタンにフォーカスを戻す（キーボードで操作している人向け）
        if (modalOpener) {
            modalOpener.focus();
            modalOpener = null;
        }
    }

    // 操作の対象を hidden 項目としてフォームに入れ直す。
    // まとめて操作するときのために、いくつでも渡せるようにしている。
    function setPaths(form, items) {
        var holder = form.querySelector('[data-paths]');
        holder.textContent = '';

        items.forEach(function (item) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'paths[]';
            input.value = item.path;
            holder.appendChild(input);
        });
    }

    // 操作対象の呼び名。フォルダか、写真か、動画か。
    function kindLabel(item) {
        if (item.isFolder) {
            return 'フォルダ： ';
        }

        return item.kind === 'video' ? '動画： ' : '写真： ';
    }

    // モーダルの見出し下に出す、操作対象の説明文
    function describe(items, suffix) {
        if (items.length === 1) {
            // ファイル名と続く文が地続きに見えないよう、あいだを空ける
            return kindLabel(items[0]) + items[0].name +
                (suffix ? ' ' + suffix : '');
        }

        return items.length + '件' + (suffix || '');
    }

    // カード（またはフォルダの行）に付けた data 属性から、操作対象を組み立てる
    function itemOf(element) {
        return {
            element: element,
            path: element.dataset.path || '',
            name: element.dataset.name || '',
            src: element.dataset.src || '',
            kind: element.dataset.kind || 'image',
            isFolder: element.dataset.type === 'dir'
        };
    }

    // 画像をダウンロードする（同一オリジンなので download 属性が効く）
    function download(url, name) {
        var link = document.createElement('a');
        link.href = url;
        link.download = name || '';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function showRename(item, opener) {
        var modal = document.getElementById('renameModal');
        if (!modal || !item) {
            return;
        }

        var form = modal.querySelector('form');
        var input = form.elements.name;

        form.elements.path.value = item.path;
        input.value = item.name;
        modal.querySelector('[data-modal-target]').textContent = describe([item]);

        openModal(modal, opener);
        input.focus();

        // 拡張子を除いた部分だけを選んでおくと、そのまま打ち替えられる
        var dot = item.isFolder ? -1 : input.value.lastIndexOf('.');
        input.setSelectionRange(0, dot > 0 ? dot : input.value.length);
    }

    // 移動先として選べないものを、選択できないようにする。
    //   ・いま置いてあるフォルダ（移動にならない）
    //   ・自分自身と、その中のフォルダ（行き先ごと動かすことになってしまう）
    function updateDestinations(form, items) {
        var select = form.elements.destination;
        var options = Array.prototype.slice.call(select.options);
        var currentDir = form.elements.dir.value;

        options.forEach(function (option) {
            var blocked = option.value === currentDir;

            items.forEach(function (item) {
                if (item.isFolder &&
                    (option.value === item.path || option.value.indexOf(item.path + '/') === 0)) {
                    blocked = true;
                }
            });

            option.disabled = blocked;
        });

        var selectable = options.filter(function (option) {
            return !option.disabled;
        });

        // 開いたときに、いまいる場所のあたりが見えるようにする。
        // 一覧が長いと、先頭のホームから探し下ろすことになってしまうため。
        // いまいるフォルダ自身は移動先にならないので、その親を選んでおく。
        var slash = currentDir.lastIndexOf('/');
        var parentDir = slash === -1 ? '' : currentDir.slice(0, slash);

        var nearby = selectable.filter(function (option) {
            return option.value === parentDir;
        });

        if (nearby.length > 0) {
            select.value = nearby[0].value;
        } else {
            select.value = selectable.length > 0 ? selectable[0].value : '';
        }

        form.querySelector('button[type="submit"]').disabled = selectable.length === 0;
    }

    // destination を渡すと、その行き先を選んだ状態で開く。
    // ドラッグ＆ドロップのように、行き先がもう決まっているときに使う。
    function showMove(items, opener, destination) {
        var modal = document.getElementById('moveModal');
        if (!modal || items.length === 0) {
            return;
        }

        var form = modal.querySelector('form');

        setPaths(form, items);
        modal.querySelector('[data-modal-target]').textContent = describe(items, 'を移動します');
        updateDestinations(form, items);

        if (typeof destination === 'string') {
            var options = Array.prototype.slice.call(form.elements.destination.options);
            var chosen = options.filter(function (option) {
                return option.value === destination && !option.disabled;
            })[0];

            if (chosen) {
                form.elements.destination.value = destination;
            }
        }

        openModal(modal, opener);
        form.elements.destination.focus();
    }

    function showDelete(items, opener) {
        var modal = document.getElementById('deleteModal');
        if (!modal || items.length === 0) {
            return;
        }

        var form = modal.querySelector('form');
        var folders = items.filter(function (item) {
            return item.isFolder;
        });

        setPaths(form, items);
        modal.querySelector('[data-modal-target]').textContent = describe(
            items,
            folders.length > 0 ? 'をゴミ箱へ移動します（フォルダは中身ごと移動します）' : 'をゴミ箱へ移動します'
        );

        openModal(modal, opener);

        // 取り消しのほうにフォーカスを置く（Enterの連打で消してしまわないように）
        modal.querySelector('[data-modal-close]').focus();
    }

    function showMkdir(opener) {
        var modal = document.getElementById('mkdirModal');
        if (!modal) {
            return;
        }

        var input = modal.querySelector('form').elements.name;
        input.value = '';

        openModal(modal, opener);
        input.focus();
    }

    // ---- フォルダ情報（info.yml）の編集 ------------------------------

    var infoModal = document.getElementById('infoModal');
    var infoRows  = infoModal ? infoModal.querySelector('[data-info-rows]') : null;

    // 開き直したときに前のいじりかけが残らないよう、最初の中身を控えておく
    var infoRowsHtml = infoRows ? infoRows.innerHTML : '';

    // 並んでいる順に name を振り直す。items[0][item] のように番号が続いていないと、
    // 受け取る側で組み直せないため、行を足したり動かしたりするたびに呼ぶ。
    function renumberInfoRows() {
        var names = ['item', 'value', 'url'];

        Array.prototype.forEach.call(
            infoRows.querySelectorAll('.info-row'),
            function (row, index) {
                Array.prototype.forEach.call(
                    row.querySelectorAll('input'),
                    function (input, i) {
                        if (names[i]) {
                            input.name = 'items[' + index + '][' + names[i] + ']';
                        }
                    }
                );
            }
        );
    }

    function addInfoRow() {
        var template = infoModal.querySelector('[data-info-template]');
        var row = template.cloneNode(true);

        row.hidden = false;
        row.removeAttribute('data-info-template');

        infoRows.appendChild(row);
        renumberInfoRows();
        row.querySelector('input').focus();
    }

    // 「ランダム」を選んだときだけ、選ぶ範囲を出す。
    // ほかを選んでいるあいだ出しておくと、効かない設定に見えてしまうため。
    var randomFrom = infoModal ? infoModal.querySelector('[data-random-from]') : null;

    function updateRandomFrom() {
        if (!randomFrom) {
            return;
        }

        var chosen = infoModal.querySelector('input[name="thumbnail"]:checked');

        randomFrom.hidden = !chosen || chosen.value !== 'random';
    }

    if (infoModal) {
        infoModal.addEventListener('change', function (event) {
            if (event.target.name === 'thumbnail') {
                updateRandomFrom();
            }
        });
    }

    function removeInfoRow(row) {
        if (row) {
            row.remove();
            renumberInfoRows();
        }
    }

    function moveInfoRow(row, down) {
        if (!row) {
            return;
        }

        var next = down ? row.nextElementSibling : row.previousElementSibling;

        if (!next) {
            return;
        }

        if (down) {
            infoRows.insertBefore(next, row);
        } else {
            infoRows.insertBefore(row, next);
        }

        renumberInfoRows();
        row.querySelector('input').focus();
    }

    // プロパティ（画面全体）。組み立てはこのファイルの終わりのほうで行う。
    // #propsView が無いページでは、showProperties は何もしないまま。
    var propsView = document.getElementById('propsView');
    var showProperties = function () {};

    function propsOpen() {
        return propsView !== null && !propsView.hidden;
    }

    function showInfo(opener) {
        if (!infoModal) {
            return;
        }

        // 前に開いたときの書きかけは残さず、画面を読み込んだ時点の内容に戻す
        infoRows.innerHTML = infoRowsHtml;

        var form = infoModal.querySelector('form');
        form.reset();

        // reset で選び直されたラジオに合わせる
        updateRandomFrom();

        openModal(infoModal, opener);
        form.elements.title.focus();
    }

    // ---- 複数選択 --------------------------------------------------

    var selectBoxes = Array.prototype.slice.call(document.querySelectorAll('[data-select]'));
    var selectionBar = document.querySelector('.selection-bar');

    // 選べるものを、画面に並んでいる順（フォルダ → 写真・動画）にそろえた配列。
    // Shift での範囲選択も、ドラッグでの矩形選択も、この並び順を基準にする。
    var selectables = Array.prototype.slice.call(
        document.querySelectorAll('.folder-item, .card')
    ).filter(function (element) {
        return element.querySelector('[data-select]') !== null;
    });

    // 範囲選択の起点。最後に1件だけ選んだ場所を覚えておく。
    var anchorIndex = -1;

    // 写真1件・フォルダ1件を包んでいる要素
    function containerOf(element) {
        return element && element.closest
            ? element.closest('.card, .folder-item')
            : null;
    }

    function boxOf(container) {
        return container ? container.querySelector('[data-select]') : null;
    }

    function isChecked(container) {
        var box = boxOf(container);

        return box ? box.checked : false;
    }

    function setChecked(container, on) {
        var box = boxOf(container);

        if (box) {
            box.checked = on;
        }
    }

    function indexOfSelectable(container) {
        return container ? selectables.indexOf(container) : -1;
    }

    function selectedItems() {
        return selectBoxes.filter(function (box) {
            return box.checked;
        }).map(function (box) {
            return itemOf(box.closest('.card, .folder-item'));
        });
    }

    function updateSelection() {
        if (!selectionBar) {
            return;
        }

        var count = selectedItems().length;

        selectionBar.querySelector('[data-selection-count]').textContent = count + '件を選択中';

        selectionBar.querySelectorAll('[data-act$="-selected"]').forEach(function (button) {
            button.disabled = count === 0;
        });

        selectionBar.querySelector('[data-act="select-all"]').textContent =
            (count > 0 && count === selectBoxes.length) ? 'すべて解除' : 'すべて選択';
    }

    function setSelecting(on) {
        document.body.classList.toggle('selecting', on);

        if (selectionBar) {
            selectionBar.hidden = !on;
        }

        if (!on) {
            selectBoxes.forEach(function (box) {
                box.checked = false;
            });

            anchorIndex = -1;
        }

        updateSelection();
    }

    // 選択モードに入っていなければ入る。
    // Cmd/Ctrl＋クリックや、ドラッグでの矩形選択から呼ぶ。
    function ensureSelecting() {
        if (!document.body.classList.contains('selecting')) {
            setSelecting(true);
        }
    }

    // 起点から相手までのあいだを、まとめて選ぶ
    function selectRange(from, to) {
        if (from < 0 || to < 0) {
            return;
        }

        var start = Math.min(from, to);
        var end   = Math.max(from, to);

        for (var i = start; i <= end; i += 1) {
            setChecked(selectables[i], true);
        }

        updateSelection();
    }

    selectBoxes.forEach(function (box) {
        box.addEventListener('change', function () {
            anchorIndex = indexOfSelectable(containerOf(box));
            updateSelection();
        });
    });

    // 一覧のクリックを、選択の操作として横取りする。
    //
    //   選択モード中のクリック … 1件ずつ選ぶ・外す
    //   Shift＋クリック        … 起点からそこまでをまとめて選ぶ
    //   Cmd/Ctrl＋クリック     … 選択モードでなくても、1件ずつ選ぶ
    //
    // サムネイルもフォルダ名もリンクなので、拡大表示やフォルダ移動が
    // 始まってしまわないよう、捕まえる側（capture）で受け取って止める。
    document.addEventListener('click', function (event) {
        var container = containerOf(event.target);
        var index = indexOfSelectable(container);

        if (index === -1) {
            return;
        }

        var range    = event.shiftKey;
        var additive = event.metaKey || event.ctrlKey;

        if (!range && !additive &&
            !document.body.classList.contains('selecting')) {
            return;
        }

        // チェックボックスそのものは、修飾キーなしなら見たままの動きに任せる
        if (!range && !additive && event.target.closest('.select-box')) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        ensureSelecting();

        // Shift でも、起点がまだなければ、そこを起点にして1件だけ選ぶ
        if (range && anchorIndex >= 0) {
            selectRange(anchorIndex, index);
            return;
        }

        setChecked(container, !isChecked(container));
        anchorIndex = index;
        updateSelection();
    }, true);

    // ---- 背景をドラッグして、囲んだものを選ぶ ----------------------
    //
    // 一覧の何もないところからドラッグを始めると、四角い枠が出て、
    // 枠に重なったものが選ばれる。選択モードに入っていなければ、
    // ドラッグを始めた時点で入る。
    //
    // 写真やフォルダの上から始めたドラッグは、今までどおり
    // 「フォルダへ落として移動」なので、ここでは相手にしない。
    // 指でのなぞりは画面を送る操作なので、マウスのときだけ動かす。

    var band = null;          // 画面に出している枠
    var bandStart = null;     // ドラッグを始めた場所（ページ全体から見た位置）
    var bandBase = [];        // ドラッグを始めた時点の、選ばれていたもの
    var bandTurnedOn = false; // このドラッグで選択モードに入ったかどうか

    // 枠を出してよい場所か。写真・フォルダやボタンの上では出さない。
    function isBlankArea(target) {
        if (!target || typeof target.closest !== 'function') {
            return false;
        }

        if (target.closest('.card, .folder-item, a, button, input, select, ' +
                'textarea, label, .selection-bar, .page-header, .pagination, ' +
                '.modal, .context-menu, .lightbox, .props-view')) {
            return false;
        }

        return target === document.body ||
            target === document.documentElement ||
            target.closest('.content') !== null;
    }

    // ページ全体から見た位置。途中で画面を送っても、ずれないようにする。
    function pagePoint(event) {
        return { x: event.pageX, y: event.pageY };
    }

    function bandRect(point) {
        return {
            left: Math.min(bandStart.x, point.x),
            top: Math.min(bandStart.y, point.y),
            right: Math.max(bandStart.x, point.x),
            bottom: Math.max(bandStart.y, point.y)
        };
    }

    function overlaps(rect, element) {
        var box = element.getBoundingClientRect();
        var left = box.left + window.pageXOffset;
        var top  = box.top + window.pageYOffset;

        return left < rect.right && left + box.width > rect.left &&
            top < rect.bottom && top + box.height > rect.top;
    }

    function updateBand(point) {
        var rect = bandRect(point);

        band.style.left   = rect.left + 'px';
        band.style.top    = rect.top + 'px';
        band.style.width  = (rect.right - rect.left) + 'px';
        band.style.height = (rect.bottom - rect.top) + 'px';

        // 枠から外れたものは、ドラッグを始める前の状態へ戻す
        selectables.forEach(function (element, i) {
            setChecked(element, overlaps(rect, element) || bandBase[i]);
        });

        updateSelection();
    }

    function endBand() {
        if (band) {
            band.remove();
            band = null;
        }

        document.body.classList.remove('band-selecting');

        // 何も選ばずに終わったなら、勝手に入った選択モードは元へ戻す
        if (bandTurnedOn && selectedItems().length === 0) {
            setSelecting(false);
        }

        bandStart = null;
        bandTurnedOn = false;

        window.removeEventListener('pointermove', onBandMove, true);
        window.removeEventListener('pointerup', endBand, true);
        window.removeEventListener('pointercancel', endBand, true);
    }

    function onBandMove(event) {
        var point = pagePoint(event);

        // 少し動かすまでは始めない。ただのクリックと区別するため。
        if (!band) {
            if (Math.abs(point.x - bandStart.x) < 5 &&
                Math.abs(point.y - bandStart.y) < 5) {
                return;
            }

            bandTurnedOn = !document.body.classList.contains('selecting');
            ensureSelecting();

            bandBase = selectables.map(isChecked);

            band = document.createElement('div');
            band.className = 'rubber-band';
            document.body.appendChild(band);
            document.body.classList.add('band-selecting');
        }

        updateBand(point);
    }

    if (selectables.length > 0) {
        document.addEventListener('pointerdown', function (event) {
            if (event.pointerType !== 'mouse' || event.button !== 0) {
                return;
            }

            if (openedModal || propsOpen() || (lightbox && !lightbox.hidden)) {
                return;
            }

            if (!isBlankArea(event.target)) {
                return;
            }

            bandStart = pagePoint(event);

            window.addEventListener('pointermove', onBandMove, true);
            window.addEventListener('pointerup', endBand, true);
            window.addEventListener('pointercancel', endBand, true);
        }, true);
    }

    // ---- ドラッグ＆ドロップで移動 ----------------------------------
    //
    // 写真・動画・フォルダを、フォルダの行やパンくずへ落として移動する。
    // 落とした時点では動かさず、行き先を選んだ状態で確認の画面を出す。
    // 掴んだ場所を間違えていても、そこで気づいて取り消せるようにするため。
    //
    // スマートフォン・タブレットのブラウザはこの仕組みに対応していないので、
    // そちらは今までどおり長押しの操作メニューから移動する。

    var moveModal = document.getElementById('moveModal');
    var draggables = Array.prototype.slice.call(document.querySelectorAll('.card, .folder-item'));

    // 掴んでいるもの。dragover の最中は dataTransfer の中身を読めないため、
    // ここに覚えておく。
    var dragging = [];

    // いま光らせているドロップ先
    var dropTarget = null;

    // いま開いているフォルダ。ここへ落としても移動にならないので受け付けない。
    function currentDir() {
        return moveModal ? moveModal.querySelector('form').elements.dir.value : '';
    }

    // 掴んだものが選択済みなら、選んだものすべてを動かす。
    function draggedItemsOf(element) {
        var item = itemOf(element);
        var chosen = selectedItems();
        var included = chosen.some(function (one) {
            return one.path === item.path;
        });

        return included ? chosen : [item];
    }

    // 落とせる場所（フォルダの行、パンくず、「上のフォルダへ」）を探す
    function dropZoneOf(target) {
        if (!target || typeof target.closest !== 'function') {
            return null;
        }

        return target.closest('.folder-item, [data-drop-path]');
    }

    // ホームは空文字なので、「行き先なし」は null で表す
    function dropPathOf(zone) {
        if (!zone) {
            return null;
        }

        return zone.classList.contains('folder-item')
            ? zone.dataset.path
            : zone.dataset.dropPath;
    }

    // 行き先として成り立つか。自分自身の中や、いまいる場所へは移せない。
    function canDropInto(path) {
        if (dragging.length === 0 || typeof path !== 'string') {
            return false;
        }

        if (path === currentDir()) {
            return false;
        }

        return !dragging.some(function (item) {
            return item.isFolder &&
                (path === item.path || path.indexOf(item.path + '/') === 0);
        });
    }

    function clearDropTarget() {
        if (dropTarget) {
            dropTarget.classList.remove('drop-target');
            dropTarget = null;
        }
    }

    function endDrag() {
        clearDropTarget();
        document.body.classList.remove('dragging');

        draggables.forEach(function (element) {
            element.classList.remove('drag-source');
        });

        dragging = [];
    }

    if (moveModal && draggables.length > 0) {
        document.addEventListener('dragstart', function (event) {
            var origin = event.target && typeof event.target.closest === 'function'
                ? event.target.closest('.card, .folder-item')
                : null;

            if (!origin || origin.getAttribute('draggable') !== 'true') {
                return;
            }

            dragging = draggedItemsOf(origin);

            event.dataTransfer.effectAllowed = 'move';

            // 何も入れないとドラッグが始まらないブラウザがあるので、名前を入れておく
            event.dataTransfer.setData('text/plain', dragging.map(function (item) {
                return item.name;
            }).join('\n'));

            document.body.classList.add('dragging');

            // まとめて動かすときに分かるよう、対象すべてに印を付ける
            draggables.forEach(function (element) {
                var path = element.dataset.path;
                var included = dragging.some(function (item) {
                    return item.path === path;
                });

                if (included) {
                    element.classList.add('drag-source');
                }
            });
        });

        // dragover で preventDefault したところだけが、落とせる場所になる
        document.addEventListener('dragover', function (event) {
            if (dragging.length === 0) {
                return;
            }

            var zone = dropZoneOf(event.target);

            if (!canDropInto(dropPathOf(zone))) {
                clearDropTarget();
                event.dataTransfer.dropEffect = 'none';
                return;
            }

            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';

            if (dropTarget !== zone) {
                clearDropTarget();
                dropTarget = zone;
                zone.classList.add('drop-target');
            }
        });

        document.addEventListener('drop', function (event) {
            if (dragging.length === 0) {
                return;
            }

            var zone = dropZoneOf(event.target);
            var path = dropPathOf(zone);

            if (!canDropInto(path)) {
                endDrag();
                return;
            }

            // リンクの上に落としたときに、そのリンクへ移動してしまうのを防ぐ
            event.preventDefault();

            var items = dragging;
            endDrag();

            showMove(items, null, path);
        });

        document.addEventListener('dragend', endDrag);
    }

    // ---- 右クリック（長押し）で出る操作メニュー ----------------------

    var contextMenu = document.getElementById('contextMenu');
    var contextItems = [];
    var suppressClick = false;

    // ヘッダーの「⋯ メニュー」から開いたときの、押したボタン。
    // もう一度押して閉じる判定と、閉じたあとのフォーカス戻しに使う。
    var contextOpener = null;

    // 権限によっては置いていない項目があるので、無ければ null が返る
    function menuItem(name) {
        return contextMenu.querySelector('[data-menu="' + name + '"]');
    }

    function showMenuItem(name, on) {
        var item = menuItem(name);

        if (item) {
            item.hidden = !on;
        }
    }

    // 右クリックした先の対象を決める。
    // 選択済みのものを右クリックしたときは、選んだもの全部をまとめて扱う。
    function targetsFor(container) {
        var box = container.querySelector('[data-select]');

        if (box && box.checked) {
            var chosen = selectedItems();
            if (chosen.length > 1) {
                return chosen;
            }
        }

        return [itemOf(container)];
    }

    function openContextMenu(x, y, items, opener) {
        contextItems = items;
        contextOpener = opener || null;

        if (contextOpener) {
            contextOpener.setAttribute('aria-expanded', 'true');
        }

        var onItem = items.length > 0;
        var single = items.length === 1;
        var label = contextMenu.querySelector('[data-context-target]');

        // 意味のある項目だけを出す。
        // プロパティは、写真・動画を1件だけ選んだときに出す
        // （フォルダは大きさや更新日時を持っていないので出さない）。
        showMenuItem('properties', single && !items[0].isFolder);
        showMenuItem('rename', single);
        showMenuItem('download', single && !items[0].isFolder);
        showMenuItem('move', onItem);
        showMenuItem('delete', onItem);
        showMenuItem('mkdir', !onItem);
        showMenuItem('info', !onItem);
        showMenuItem('select', !onItem);

        var selectItem = menuItem('select');

        if (selectItem) {
            selectItem.textContent =
                document.body.classList.contains('selecting') ? '選択をやめる' : '複数選択';
        }

        label.hidden = !onItem;
        label.textContent = onItem ? describe(items) : '';

        // 出せる項目がひとつも無いなら（見るだけの人が余白を右クリックしたときなど）、
        // 空の枠だけが出てしまうので開かない
        var usable = Array.prototype.some.call(
            contextMenu.querySelectorAll('[data-menu]'),
            function (element) {
                return !element.hidden;
            });

        if (!usable) {
            contextItems = [];

            if (contextOpener) {
                contextOpener.setAttribute('aria-expanded', 'false');
                contextOpener = null;
            }

            return false;
        }

        // いったん表示して大きさを測り、画面からはみ出さない位置に置く
        contextMenu.hidden = false;
        contextMenu.style.left = '0px';
        contextMenu.style.top = '0px';

        var box = contextMenu.getBoundingClientRect();

        // 右クリックは押した位置から右へ広げる。ボタンから開いたときは、
        // ボタンの右端にメニューの右端をそろえる（x にはボタンの右端が入る）。
        var left = contextOpener ? x - box.width : x;

        contextMenu.style.left = Math.max(8, Math.min(left, window.innerWidth - box.width - 8)) + 'px';
        contextMenu.style.top = Math.max(8, Math.min(y, window.innerHeight - box.height - 8)) + 'px';

        return true;
    }

    function closeContextMenu() {
        if (contextMenu && !contextMenu.hidden) {
            contextMenu.hidden = true;
            contextItems = [];

            if (contextOpener) {
                contextOpener.setAttribute('aria-expanded', 'false');
                contextOpener = null;
            }
        }
    }

    function runMenu(name) {
        var items = contextItems;
        var opener = contextOpener;

        closeContextMenu();

        switch (name) {
            case 'rename':
                showRename(items[0], opener);
                break;
            case 'move':
                showMove(items, opener);
                break;
            case 'delete':
                showDelete(items, opener);
                break;
            case 'download':
                download(items[0].src, items[0].name);
                break;
            case 'mkdir':
                showMkdir(opener);
                break;
            case 'info':
                showInfo(opener);
                break;
            case 'select':
                setSelecting(!document.body.classList.contains('selecting'));
                break;
            case 'properties':
                showProperties(items[0]);
                break;
        }
    }

    if (contextMenu) {
        document.addEventListener('contextmenu', function (event) {
            var target = event.target;

            // ヘッダーの検索欄や拡大表示では、ブラウザ既定のメニューを残す
            if (!target || !target.closest || !target.closest('.content')) {
                return;
            }

            var container = target.closest('.card, .folder-item');

            if (openContextMenu(event.clientX, event.clientY,
                    container ? targetsFor(container) : [])) {
                event.preventDefault();
            }
        });

        // スマホ・タブレット向けの長押し。
        // 指が動いたら取りやめる（画面を送っているだけのときに出ないように）。
        var pressTimer = null;

        function cancelPress() {
            window.clearTimeout(pressTimer);
            pressTimer = null;
        }

        document.addEventListener('touchstart', function (event) {
            var target = event.target;
            if (!target || !target.closest || !target.closest('.content')) {
                return;
            }

            var container = target.closest('.card, .folder-item');
            var touch = event.changedTouches[0];

            cancelPress();
            pressTimer = window.setTimeout(function () {
                pressTimer = null;

                // 長押しのあとに続くタップで拡大表示が開いてしまうのを防ぐ
                suppressClick = openContextMenu(touch.clientX, touch.clientY,
                    container ? targetsFor(container) : []);
            }, 500);
        }, { passive: true });

        document.addEventListener('touchmove', cancelPress, { passive: true });
        document.addEventListener('touchend', cancelPress);
        document.addEventListener('touchcancel', cancelPress);

        // 長押しの直後の1回だけ、クリックをなかったことにする
        document.addEventListener('click', function (event) {
            if (suppressClick) {
                suppressClick = false;
                event.preventDefault();
                event.stopPropagation();
            }
        }, true);

        document.addEventListener('scroll', closeContextMenu, true);
        window.addEventListener('resize', closeContextMenu);
    }

    // ---- 設定 ------------------------------------------------------
    // 動画の見え方（再生し始めるときに音を消すか・どの大きさで出すか）を、
    // このブラウザに覚えさせる。サーバーには送らないので、同じページを
    // 見ている他の人の画面は変わらない。

    var SETTINGS_KEY = 'pv-settings';

    var settingsModal = document.getElementById('settingsModal');
    var viewButton = document.querySelector('.view-button');

    // まだ一度も設定を変えていない人に使う値。
    // index.php が config.php の内容を body の data 属性に書き出している。
    var settingDefaults = {
        videoMuted: document.body.dataset.videoMuted === '1',
        videoSize:  document.body.dataset.videoSize === 'fit' ? 'fit' : 'original',
        view:       document.body.dataset.view === 'list' ? 'list' : 'grid'
    };

    var settings = {
        videoMuted: settingDefaults.videoMuted,
        videoSize:  settingDefaults.videoSize,
        view:       settingDefaults.view
    };

    // localStorage は、ブラウザの設定によっては読み書きで例外になる。
    // 使えないときは覚えられないだけで、開いているあいだは設定どおりに動く。
    function loadSettings() {
        var saved;

        try {
            saved = JSON.parse(window.localStorage.getItem(SETTINGS_KEY) || '{}');
        } catch (e) {
            return;
        }

        if (!saved || typeof saved !== 'object') {
            return;
        }

        if (typeof saved.videoMuted === 'boolean') {
            settings.videoMuted = saved.videoMuted;
        }

        if (saved.videoSize === 'fit' || saved.videoSize === 'original') {
            settings.videoSize = saved.videoSize;
        }

        if (saved.view === 'list' || saved.view === 'grid') {
            settings.view = saved.view;
        }
    }

    function saveSettings() {
        try {
            window.localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings));
        } catch (e) {
            // 覚えられなくても、そのまま使えるようにしておく
        }
    }

    // スマートフォンのように画面が狭いときは、グリッドを使わずリストに固定する。
    // 覚えている設定そのものは書き換えないので、広い画面で開き直せば元に戻る。
    var narrowScreen = window.matchMedia('(max-width: 600px)');

    function currentView() {
        return narrowScreen.matches ? 'list' : settings.view;
    }

    // いまの設定を画面に反映する。
    // 大きさの切り替えは、CSS が body の data 属性を見て行う。
    function applySettings() {
        var view = currentView();

        document.body.dataset.videoMuted = settings.videoMuted ? '1' : '0';
        document.body.dataset.videoSize  = settings.videoSize;
        document.body.dataset.view       = view;

        // ボタンの中身は CSS が入れ替えるので、読み上げ用の名前だけ書き換える
        if (viewButton) {
            viewButton.setAttribute('aria-label',
                view === 'list' ? 'グリッド表示に切り替え' : 'リスト表示に切り替え');
        }

        // 再生中なら、閉じずにその場で音のありなしを切り替える
        var playing = document.getElementById('lbVideo');
        if (playing && !playing.hidden) {
            playing.muted = settings.videoMuted;
        }

        updateSettingsUi();
    }

    // 設定画面の選択肢を、いまの設定に合わせる
    function updateSettingsUi() {
        if (settingsModal) {
            var radios = settingsModal.querySelectorAll('input[type="radio"]');

            Array.prototype.forEach.call(radios, function (radio) {
                var name  = radio.dataset.setting;
                var value = settings[name];

                if (name === 'videoMuted') {
                    value = settings.videoMuted ? '1' : '0';
                }

                radio.checked = radio.value === value;
            });
        }
    }

    function changeSetting(change) {
        change();
        saveSettings();
        applySettings();
    }

    if (settingsModal) {
        settingsModal.addEventListener('change', function (event) {
            var radio = event.target;

            if (!radio || !radio.dataset || !radio.dataset.setting) {
                return;
            }

            changeSetting(function () {
                if (radio.dataset.setting === 'videoMuted') {
                    settings.videoMuted = radio.value === '1';
                } else if (radio.dataset.setting === 'view') {
                    settings.view = radio.value === 'list' ? 'list' : 'grid';
                } else {
                    settings.videoSize = radio.value === 'fit' ? 'fit' : 'original';
                }
            });
        });
    }

    // 画面を回したり窓の幅を変えたりしたときも、その場で切り替える。
    // addEventListener に対応していない古いブラウザ向けに addListener も見る。
    if (typeof narrowScreen.addEventListener === 'function') {
        narrowScreen.addEventListener('change', applySettings);
    } else if (typeof narrowScreen.addListener === 'function') {
        narrowScreen.addListener(applySettings);
    }

    loadSettings();
    applySettings();

    // ---- 操作ボタンの割り振り --------------------------------------

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!target || !target.closest) {
            return;
        }

        var chosen = target.closest('[data-menu]');

        if (chosen) {
            event.preventDefault();
            runMenu(chosen.dataset.menu);
            return;
        }

        // 閉じる前に控えておく。同じボタンをもう一度押したときは
        // 開き直さず、閉じたままにするため。
        var previousOpener = contextOpener;

        closeContextMenu();

        var trigger = target.closest('[data-act]');

        if (trigger) {
            event.preventDefault();

            switch (trigger.dataset.act) {
                case 'more':
                    if (previousOpener !== trigger) {
                        var rect = trigger.getBoundingClientRect();
                        openContextMenu(rect.right, rect.bottom + 6, [], trigger);
                    }
                    break;
                case 'mkdir':
                    showMkdir(trigger);
                    break;
                case 'info-add':
                    addInfoRow();
                    break;
                case 'info-remove':
                    removeInfoRow(trigger.closest('.info-row'));
                    break;
                case 'info-up':
                    moveInfoRow(trigger.closest('.info-row'), false);
                    break;
                case 'info-down':
                    moveInfoRow(trigger.closest('.info-row'), true);
                    break;
                case 'settings':
                    if (settingsModal) {
                        updateSettingsUi();
                        openModal(settingsModal, trigger);
                    }
                    break;
                case 'settings-reset':
                    changeSetting(function () {
                        settings.videoMuted = settingDefaults.videoMuted;
                        settings.videoSize  = settingDefaults.videoSize;
                        settings.view       = settingDefaults.view;
                    });
                    break;
                case 'view':
                    changeSetting(function () {
                        settings.view = settings.view === 'list' ? 'grid' : 'list';
                    });
                    break;
                case 'select-mode':
                    setSelecting(!document.body.classList.contains('selecting'));
                    break;
                case 'select-all':
                    var fill = selectedItems().length !== selectBoxes.length;
                    selectBoxes.forEach(function (box) {
                        box.checked = fill;
                    });
                    updateSelection();
                    break;
                case 'move-selected':
                    showMove(selectedItems(), trigger);
                    break;
                case 'delete-selected':
                    showDelete(selectedItems(), trigger);
                    break;
            }
            return;
        }

        // 「キャンセル」ボタンは、どの画面でも閉じる
        if (target.closest('[data-modal-close]')) {
            closeModal(true);
            return;
        }

        // パネルの外側のクリック。data-modal-keep の画面では閉じない。
        if (openedModal && target === openedModal) {
            closeModal();
        }
    });

    // Escape は、開いているものを内側から順に閉じていく
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }

        if (contextMenu && !contextMenu.hidden) {
            event.preventDefault();
            closeContextMenu();
            return;
        }

        if (openedModal) {
            event.preventDefault();

            // data-modal-keep の画面は、closeModal() の中で閉じずに戻ってくる
            closeModal();
            return;
        }

        // 拡大表示中は、それを閉じる処理（ライトボックス側）に任せる
        if (lightbox && !lightbox.hidden) {
            return;
        }

        if (document.body.classList.contains('selecting')) {
            event.preventDefault();
            setSelecting(false);
        }
    });

    // ---- Backspace で上のフォルダへ --------------------------------

    var lightbox = document.getElementById('lightbox');

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Backspace') {
            return;
        }

        // 操作用のモーダルやプロパティを開いている間は移動しない
        if (openedModal || propsOpen()) {
            return;
        }

        // 入力欄では通常どおり1文字消す
        var target = event.target;
        if (target && (target.tagName === 'INPUT' ||
                       target.tagName === 'TEXTAREA' ||
                       target.tagName === 'SELECT' ||
                       target.isContentEditable)) {
            return;
        }

        event.preventDefault();

        // 拡大表示中は閉じるだけにする（この後のハンドラが処理する）
        if (lightbox && !lightbox.hidden) {
            return;
        }

        var up = document.querySelector('a.button.up');
        if (up) {
            window.location.href = up.getAttribute('href');
        }
    });

    // ---- 貼り付いたヘッダーの高さ ----------------------------------
    // 枚数とページ送りの帯を、ヘッダーのすぐ下に貼り付けるために、
    // 実際の高さを CSS へ渡す。パンくずが折り返すなどで高さが変わるので、
    // 幅が変わったら測り直す。

    var pageHeader = document.querySelector('.page-header');
    var listingBar = document.querySelector('.listing-bar');

    // 画面の上に貼り付いているものの、いちばん下の位置。
    // ここより上に来たものは隠れてしまうので、スクロールの目安に使う。
    function stuckBottom() {
        var bottom = pageHeader ? pageHeader.getBoundingClientRect().bottom : 0;

        if (listingBar) {
            bottom = Math.max(bottom, listingBar.getBoundingClientRect().bottom);
        }

        return bottom;
    }

    function updateHeaderHeight() {
        if (!pageHeader) {
            return;
        }

        document.documentElement.style.setProperty(
            '--header-h', pageHeader.offsetHeight + 'px');
    }

    updateHeaderHeight();
    window.addEventListener('resize', updateHeaderHeight);

    // ---- キーボードでのフォーカス移動 --------------------------------
    //
    // Tab で一覧に入ったあと、矢印キーで写真・動画・フォルダのあいだを動ける。
    // グリッドでもリストでも使えるよう、上下の行き先は画面上の位置から探す。
    //
    // 矢印キーを横取りするのは、一覧の中にフォーカスがあるときだけにしている。
    // それ以外の場所では、いつもどおり画面の上下が動くようにするため。

    // 移動できるもの。フォルダの行と、写真・動画のサムネイル。
    // どちらもリンクなので、Enter を押せばそのまま開ける。
    function navItems() {
        return Array.prototype.slice.call(
            document.querySelectorAll('.folder-item .folder, .card .thumb'));
    }

    // フォーカスを移したものが、貼り付いた帯や画面の外に隠れないようにする
    function focusItem(element) {
        element.focus({ preventScroll: true });

        var box = element.getBoundingClientRect();
        var top = stuckBottom();
        var margin = 8;

        if (box.top < top + margin) {
            window.scrollBy(0, box.top - top - margin);
        } else if (box.bottom > window.innerHeight - margin) {
            window.scrollBy(0, box.bottom - window.innerHeight + margin);
        }
    }

    // 上下の行き先を、画面上の位置から探す。
    // まず隣の行（いちばん近い段）を見つけ、そのなかで左右の位置がいちばん近いものを選ぶ。
    // リストのように1列で並んでいるときは、自然に「1つ前・1つ後ろ」になる。
    function neighborInRow(items, index, down) {
        var box = items[index].getBoundingClientRect();
        var center = box.left + box.width / 2;
        var gap = 2; // 同じ行とみなす、ごくわずかなずれ
        var rowTop = null;
        var best = -1;
        var bestDistance = Infinity;

        items.forEach(function (item, i) {
            var candidate = item.getBoundingClientRect();

            if (down ? candidate.top <= box.top + gap : candidate.top >= box.top - gap) {
                return;
            }

            // いちばん近い行が見つかったら、それより遠い行は捨ててやり直す
            if (rowTop === null ||
                (down ? candidate.top < rowTop - gap : candidate.top > rowTop + gap)) {
                rowTop = candidate.top;
                best = -1;
                bestDistance = Infinity;
            } else if (Math.abs(candidate.top - rowTop) > gap) {
                return;
            }

            var distance = Math.abs(candidate.left + candidate.width / 2 - center);

            if (distance < bestDistance) {
                bestDistance = distance;
                best = i;
            }
        });

        return best;
    }

    // 文字を打ち込む場所かどうか。ここでは矢印キーを横取りしない。
    function isTextEntry(element) {
        var tag = element.tagName ? element.tagName.toLowerCase() : '';

        return tag === 'input' || tag === 'select' || tag === 'textarea' ||
            element.isContentEditable === true;
    }

    // Shift を押しながら矢印で選ぶあいだ、押す前の選択を覚えておく。
    // 行き過ぎて戻したときに、通り過ぎたものを選び直せるようにするため。
    var keyBase = null;

    document.addEventListener('keyup', function (event) {
        if (event.key === 'Shift') {
            keyBase = null;
        }
    });

    document.addEventListener('keydown', function (event) {
        // 修飾キーを押しながらの矢印は、ブラウザ自身の操作に譲る。
        // Shift だけは、範囲を選びながら動くのに使う。
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }

        // 拡大表示・操作モーダル・操作メニュー・プロパティを開いているあいだは、
        // そちらに任せる
        if (openedModal || propsOpen() || (lightbox && !lightbox.hidden) ||
            (contextMenu && !contextMenu.hidden)) {
            return;
        }

        var focused = document.activeElement;

        if (!focused || !focused.closest) {
            return;
        }

        var current = focused.closest('.folder-item .folder, .card .thumb');

        // 一覧の中にフォーカスがないときは、矢印キーで一覧の端から入れるようにする。
        // 右・下なら先頭（左上）、左・上なら末尾（右下）。
        // 検索ボックスなどの入力中は、いつもどおりカーソル移動に使えるようにする。
        if (!current) {
            if (isTextEntry(focused)) {
                return;
            }

            if (event.shiftKey) {
                return;
            }

            var edge = -1;
            var entryItems = navItems();

            if (entryItems.length === 0) {
                return;
            }

            switch (event.key) {
                case 'ArrowRight':
                case 'ArrowDown':
                    edge = 0;
                    break;
                case 'ArrowLeft':
                case 'ArrowUp':
                    edge = entryItems.length - 1;
                    break;
                default:
                    return;
            }

            event.preventDefault();
            focusItem(entryItems[edge]);

            return;
        }

        // Shift＋Enter は、範囲を選んでいる途中なので開かない
        if (event.shiftKey && event.key === 'Enter') {
            return;
        }

        // Enter は、リンクを押したときと同じ扱い（ブラウザに任せる）。
        // ただしリストで、すでに詳細を出している行なら、そこから拡大・再生に進む。
        if (event.key === 'Enter') {
            var card = current.closest('.card');

            if (document.body.dataset.view === 'list' &&
                !document.body.classList.contains('selecting') &&
                card && card.classList.contains('current')) {
                event.preventDefault();
                current.dispatchEvent(new MouseEvent('dblclick', {
                    bubbles: true,
                    cancelable: true
                }));
            }

            return;
        }

        var items = navItems();
        var index = items.indexOf(current);

        if (index === -1) {
            return;
        }

        var next = -1;

        switch (event.key) {
            case 'ArrowRight':
                next = index + 1;
                break;
            case 'ArrowLeft':
                next = index - 1;
                break;
            case 'ArrowDown':
                next = neighborInRow(items, index, true);
                break;
            case 'ArrowUp':
                next = neighborInRow(items, index, false);
                break;
            case 'Home':
                next = 0;
                break;
            case 'End':
                next = items.length - 1;
                break;
            default:
                return;
        }

        event.preventDefault();

        if (next < 0 || next >= items.length || next === index) {
            return;
        }

        var moved = containerOf(items[next]);

        if (event.shiftKey) {
            ensureSelecting();

            if (anchorIndex < 0) {
                anchorIndex = indexOfSelectable(containerOf(current));
            }

            // 押しはじめの状態を覚えてから、起点とのあいだを選び直す
            if (keyBase === null) {
                keyBase = selectables.map(isChecked);
            }

            selectables.forEach(function (element, i) {
                setChecked(element, keyBase[i]);
            });

            selectRange(anchorIndex, indexOfSelectable(moved));
        } else {
            // Shift なしで動いたら、次の範囲選択はそこから始める
            anchorIndex = indexOfSelectable(moved);
        }

        focusItem(items[next]);
    });

    // ---- プロパティ --------------------------------------------------
    //
    // 右クリック（スマホは長押し）の操作メニューから開き、画面全体に出す。
    // 画面のどこかを押すか Esc で、元の画面へ戻る。

    if (propsView) {
        var propsThumb = propsView.querySelector('[data-props-thumb]');
        var propsName  = propsView.querySelector('[data-props-name]');
        var propsKind  = propsView.querySelector('[data-props-kind]');
        var propsSize  = propsView.querySelector('[data-props-size]');
        var propsDate  = propsView.querySelector('[data-props-date]');
        var propsPath  = propsView.querySelector('[data-props-path]');

        // 一覧のサムネイルと同じ見せ方。動画は先頭付近の1コマを出す。
        function fillPropsThumb(card) {
            var node;

            propsThumb.textContent = '';

            if (card.dataset.kind === 'video') {
                node = document.createElement('video');
                node.preload = 'metadata';
                node.muted = true;
                node.playsInline = true;
                node.tabIndex = -1;
                node.src = card.dataset.src + '#t=0.1';

                propsThumb.appendChild(node);

                // 後から作った要素は、読み込みを促さないと1コマも出ないことがある
                node.load();

                return;
            }

            node = document.createElement('img');
            node.src = card.dataset.src;
            node.alt = card.dataset.name || '';
            node.decoding = 'async';

            propsThumb.appendChild(node);
        }

        function closeProps() {
            if (propsView.hidden) {
                return;
            }

            propsView.hidden = true;
            document.body.style.overflow = '';

            // 見えないところで動画の読み込みが続かないよう、中身を消す
            propsThumb.textContent = '';
        }

        // 一覧のカードに付いている data 属性を、そのまま見せる
        showProperties = function (item) {
            var card = item ? item.element : null;

            if (!card) {
                return;
            }

            var path = card.dataset.path || '';
            var slash = path.lastIndexOf('/');

            fillPropsThumb(card);

            propsName.textContent = card.dataset.name || '';
            propsKind.textContent = card.dataset.kind === 'video' ? '動画' : '写真';
            propsSize.textContent = card.dataset.size || '';
            propsDate.textContent = card.dataset.date || '';
            propsPath.textContent = slash === -1 ? 'ホーム' : path.slice(0, slash);

            propsView.hidden = false;
            document.body.style.overflow = 'hidden';

            // Esc で閉じられるよう、キーの行き先をこちらへ移す
            propsView.focus({ preventScroll: true });
        };

        // どこを押しても閉じる。
        // touchend では閉じない（中身が長いときの指でのスクロールと区別できないため）。
        propsView.addEventListener('click', closeProps);

        // 他の Esc の処理より先に判断したいので、捕まえる側（capture）で受け取る
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !propsView.hidden) {
                event.stopPropagation();
                closeProps();
            }
        }, true);
    }

    // ---- ドラッグ＆ドロップで取り込む --------------------------------
    // 画面のどこに落としても、いま開いているフォルダに入る。
    // フォルダごと落としたときは、中の構成をそのまま作り直す。
    //
    // 大きな動画でも上げられるよう、ファイルは小さく切って順に送り、
    // サーバー側（upload.php）で継ぎ足してもらう。1回のリクエストが
    // 小さいままなら、共有サーバーの upload_max_filesize などに届かない。

    var uploadDrop = document.getElementById('uploadDrop');
    var uploadStatus = document.getElementById('uploadStatus');

    // 閲覧専用のときは要素ごと置いていない。古いブラウザでは何もしない。
    if (uploadDrop && window.FormData && window.File && File.prototype.slice) {
        var upConfig = {
            token: uploadDrop.dataset.token,
            root: uploadDrop.dataset.root,
            dir: uploadDrop.dataset.dir,
            chunk: parseInt(uploadDrop.dataset.chunk, 10) || 1048576,
            extensions: (uploadDrop.dataset.extensions || '').split(',').filter(Boolean)
        };

        var upTitle = uploadStatus.querySelector('[data-upload-title]');
        var upName = uploadStatus.querySelector('[data-upload-name]');
        var upBar = uploadStatus.querySelector('[data-upload-bar]');
        var upTrack = uploadStatus.querySelector('[data-upload-track]');
        var upNotes = uploadStatus.querySelector('[data-upload-notes]');
        var upClose = uploadStatus.querySelector('[data-upload-close]');

        // 送っている最中は、次のドロップを受け付けない
        var upBusy = false;

        // dragenter / dragleave は入れ子の要素でも起きるので、
        // 出入りの数を数えて、本当に画面から出たときだけ隠す。
        var upDepth = 0;

        // ---- 送るものを組み立てる ------------------------------------

        function upExtensionOf(name) {
            var dot = name.lastIndexOf('.');

            return dot < 0 ? '' : name.slice(dot + 1).toLowerCase();
        }

        function upAccepted(name) {
            return upConfig.extensions.indexOf(upExtensionOf(name)) >= 0;
        }

        function upRandomId() {
            var bytes = new Uint8Array(16);

            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(bytes);
            } else {
                for (var i = 0; i < bytes.length; i++) {
                    bytes[i] = Math.floor(Math.random() * 256);
                }
            }

            return Array.prototype.map.call(bytes, function (one) {
                return ('0' + one.toString(16)).slice(-2);
            }).join('');
        }

        function upFlatten(lists) {
            return lists.reduce(function (all, one) {
                return all.concat(one);
            }, []);
        }

        // フォルダの中身を読む。readEntries は一度に全部返すとは限らないので、
        // 空が返るまで呼び続ける決まりになっている。
        function upReadAll(reader) {
            return new Promise(function (resolve) {
                var found = [];

                function step() {
                    reader.readEntries(function (batch) {
                        if (batch.length === 0) {
                            resolve(found);
                            return;
                        }

                        found = found.concat(batch);
                        step();
                    }, function () {
                        resolve(found);
                    });
                }

                step();
            });
        }

        // 落とされたものを { file, sub } の並びにする。
        // sub は落とした先から見た入れ子のフォルダで、ファイル単体なら空。
        function upWalk(entry, sub) {
            if (entry.isFile) {
                return new Promise(function (resolve) {
                    entry.file(function (file) {
                        resolve([{ file: file, sub: sub }]);
                    }, function () {
                        resolve([]);
                    });
                });
            }

            if (!entry.isDirectory) {
                return Promise.resolve([]);
            }

            var child = sub === '' ? entry.name : sub + '/' + entry.name;

            return upReadAll(entry.createReader()).then(function (children) {
                return Promise.all(children.map(function (one) {
                    return upWalk(one, child);
                })).then(upFlatten);
            });
        }

        function upCollect(transfer) {
            var items = transfer.items;
            var entries = [];

            if (items && items.length && typeof items[0].webkitGetAsEntry === 'function') {
                for (var i = 0; i < items.length; i++) {
                    var entry = items[i].webkitGetAsEntry();

                    if (entry) {
                        entries.push(entry);
                    }
                }
            }

            // フォルダをたどれないブラウザでは、ファイルだけを受け取る
            if (entries.length === 0) {
                var plain = [];

                for (var j = 0; j < transfer.files.length; j++) {
                    plain.push({ file: transfer.files[j], sub: '' });
                }

                return Promise.resolve(plain);
            }

            return Promise.all(entries.map(function (entry) {
                return upWalk(entry, '');
            })).then(upFlatten);
        }

        // ---- 送る ----------------------------------------------------

        // 1つのファイルを、かけらに切って順に送る。
        // onProgress には 0〜1 の進み具合が渡る。
        function upSend(item, onProgress) {
            var file = item.file;
            var total = Math.max(1, Math.ceil(file.size / upConfig.chunk));
            var id = upRandomId();
            var index = 0;

            function step() {
                var start = index * upConfig.chunk;
                var body = new FormData();

                body.append('token', upConfig.token);
                body.append('root', upConfig.root);
                body.append('dir', upConfig.dir);
                body.append('id', id);
                body.append('index', String(index));
                body.append('total', String(total));
                body.append('name', file.name);
                body.append('sub', item.sub);
                body.append('chunk', file.slice(start, start + upConfig.chunk));

                return fetch('upload.php', {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin'
                }).then(function (response) {
                    return response.json().catch(function () {
                        throw new Error('サーバーから読み取れる返事がありませんでした。');
                    });
                }).then(function (result) {
                    if (!result.ok) {
                        throw new Error(result.message || '取り込めませんでした。');
                    }

                    index++;
                    onProgress(index / total);

                    return index < total ? step() : result;
                });
            }

            return step();
        }

        // ---- 進み具合の表示 ------------------------------------------

        function upSetBar(ratio) {
            var percent = Math.round(Math.max(0, Math.min(1, ratio)) * 100);

            upBar.style.width = percent + '%';
            upTrack.setAttribute('aria-valuenow', String(percent));
        }

        function upNote(text) {
            var line = document.createElement('li');

            line.textContent = text;
            upNotes.appendChild(line);
            upNotes.hidden = false;
        }

        function upStart(count) {
            upBusy = true;
            upNotes.textContent = '';
            upNotes.hidden = true;
            upClose.hidden = true;
            upTrack.hidden = false;
            uploadStatus.hidden = false;
            uploadStatus.classList.remove('done');
            upTitle.textContent = '取り込んでいます（全 ' + count + ' 件）';
            upSetBar(0);
        }

        // 終わったときの表示。うまくいっただけなら、そのまま読み込み直して
        // 取り込んだものを一覧に出す。伝えることがあるときは、
        // 読んでもらってから閉じる（閉じたときに読み込み直す）。
        function upFinish(saved, quiet) {
            upBusy = false;
            upTrack.hidden = true;
            upName.textContent = '';

            if (saved > 0 && quiet) {
                upTitle.textContent = '取り込みました。読み込み直しています…';
                window.location.reload();
                return;
            }

            uploadStatus.classList.add('done');
            upTitle.textContent = saved > 0
                ? saved + ' 件を取り込みました'
                : '取り込めたものはありません';
            upClose.hidden = false;
            upClose.focus();
        }

        upClose.addEventListener('click', function () {
            uploadStatus.hidden = true;
            window.location.reload();
        });

        // ---- 順に送る ------------------------------------------------

        function upRun(items) {
            // 表示できない種類は、送る前に外しておく
            var skipped = [];

            var queue = items.filter(function (item) {
                if (upAccepted(item.file.name)) {
                    return true;
                }

                skipped.push(item.file.name);

                return false;
            });

            if (items.length === 0) {
                return;
            }

            upStart(queue.length);

            var saved = 0;
            var failed = 0;
            var renamed = 0;
            var count = queue.length;

            skipped.forEach(function (name) {
                upNote('「' + name + '」は取り込める種類ではないので見送りました。');
            });

            function next() {
                if (queue.length === 0) {
                    if (renamed > 0) {
                        upNote(renamed + ' 件は同じ名前があったため、番号を付けて取り込みました。');
                    }

                    upFinish(saved, skipped.length === 0 && failed === 0 && renamed === 0);
                    return;
                }

                var item = queue.shift();
                var done = count - queue.length;
                var label = item.sub === '' ? item.file.name : item.sub + '/' + item.file.name;

                upName.textContent = done + ' / ' + count + '　' + label;
                upSetBar(0);

                upSend(item, upSetBar).then(function (result) {
                    saved++;

                    if (result.renamed) {
                        renamed++;
                    }
                }).catch(function (error) {
                    failed++;
                    upNote('「' + label + '」: ' + error.message);
                }).then(next);
            }

            next();
        }

        // ---- 落とす場所 ----------------------------------------------

        // 内側のドラッグ（並べ替え・移動）と見分ける。
        // 外から持ち込まれたものだけ Files が入っている。
        function upHasFiles(event) {
            var types = event.dataTransfer && event.dataTransfer.types;

            return !!types && Array.prototype.indexOf.call(types, 'Files') >= 0;
        }

        document.addEventListener('dragenter', function (event) {
            if (!upHasFiles(event) || upBusy) {
                return;
            }

            upDepth++;
            uploadDrop.hidden = false;
        });

        document.addEventListener('dragover', function (event) {
            if (!upHasFiles(event) || upBusy) {
                return;
            }

            // ここで止めておかないと、ブラウザがそのファイルを開いてしまう
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        });

        document.addEventListener('dragleave', function (event) {
            if (!upHasFiles(event)) {
                return;
            }

            upDepth--;

            if (upDepth <= 0) {
                upDepth = 0;
                uploadDrop.hidden = true;
            }
        });

        document.addEventListener('drop', function (event) {
            if (!upHasFiles(event)) {
                return;
            }

            event.preventDefault();

            upDepth = 0;
            uploadDrop.hidden = true;

            if (upBusy) {
                return;
            }

            upCollect(event.dataTransfer).then(upRun);
        });

        // 送っている途中で閉じられると、中途半端なものが残る
        window.addEventListener('beforeunload', function (event) {
            if (upBusy) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    // ---- ライトボックス --------------------------------------------

    var thumbs = Array.prototype.slice.call(document.querySelectorAll('.thumb'));

    if (!lightbox || thumbs.length === 0) {
        return;
    }

    var image = lightbox.querySelector('.lb-image');
    var video = lightbox.querySelector('.lb-video');
    var nameLabel = lightbox.querySelector('.lb-name');
    var metaLabel = lightbox.querySelector('.lb-meta');
    var current = 0;
    var lastFocused = null;

    // いま表示しているのが動画かどうか。キー操作とスワイプの扱いを分けるために持つ。
    var showingVideo = false;

    function isVideoThumb(thumb) {
        return thumb.dataset.kind === 'video';
    }

    // 動画を止めて、読み込みも打ち切る。
    // src を外さずに隠すだけだと、見えないまま通信が続くことがある。
    function stopVideo() {
        video.pause();
        video.removeAttribute('src');
        video.load();
        video.hidden = true;
    }

    function show(index) {
        if (index < 0) {
            index = thumbs.length - 1;
        } else if (index >= thumbs.length) {
            index = 0;
        }

        current = index;

        var thumb = thumbs[index];
        var src = thumb.getAttribute('href');

        showingVideo = isVideoThumb(thumb);

        // 動画は「見ている途中で誤って閉じてしまう」と困るので、
        // 背景クリックやスワイプで閉じない・めくらない作りに切り替える。
        lightbox.classList.toggle('viewer', showingVideo);

        if (showingVideo) {
            image.hidden = true;
            image.removeAttribute('src');

            video.hidden = false;

            // 設定で「音を消して再生し始める」を選んでいるときはミュートにする。
            // 見ている途中で音を出したくなったら、プレーヤーの音量ボタンで戻せる。
            video.muted = document.body.dataset.videoMuted === '1';
            video.src = src;

            // 開いた（めくった）のは利用者の操作なので、そのまま再生を試みる。
            // ブラウザに断られることもあるため、失敗しても何もしない
            // （再生ボタンを押せば再生できる）。
            var played = video.play();
            if (played && typeof played.catch === 'function') {
                played.catch(function () {});
            }
        } else {
            stopVideo();

            image.hidden = false;
            image.src = src;
            image.alt = thumb.dataset.name || '';
        }

        nameLabel.textContent = thumb.dataset.name || '';
        metaLabel.textContent = thumb.dataset.meta || '';

        preload(index + 1);
        preload(index - 1);
    }

    // 前後の画像を先読みしておくと、めくったときの待ち時間が減る。
    // 動画は容量が大きいので先読みしない。
    function preload(index) {
        if (index < 0 || index >= thumbs.length || isVideoThumb(thumbs[index])) {
            return;
        }
        var img = new Image();
        img.src = thumbs[index].getAttribute('href');
    }

    function open(index) {
        lastFocused = document.activeElement;
        show(index);
        lightbox.hidden = false;
        document.body.style.overflow = 'hidden';

        // 動画はプレーヤーにフォーカスを移す。こうするとスペースキーで
        // 再生・一時停止ができる（閉じるボタンに当たってしまわない）。
        if (showingVideo) {
            video.focus();
        } else {
            lightbox.querySelector('.lb-close').focus();
        }
    }

    function close() {
        lightbox.hidden = true;
        lightbox.classList.remove('viewer');
        image.removeAttribute('src');
        stopVideo();
        showingVideo = false;
        document.body.style.overflow = '';

        if (lastFocused) {
            lastFocused.focus();
        }
    }

    // クリックしたらすぐ開く。ダウンロードは右クリックメニューにあるので、
    // ダブルクリックかどうかを待って見極める必要はない。
    thumbs.forEach(function (thumb, index) {
        thumb.addEventListener('click', function (event) {
            event.preventDefault();

            // 選択モード中のクリックは、選択の処理が先に受け取って止めている

            open(index);
        });
    });

    lightbox.querySelector('.lb-close').addEventListener('click', close);
    lightbox.querySelector('.lb-prev').addEventListener('click', function () {
        show(current - 1);
    });
    lightbox.querySelector('.lb-next').addEventListener('click', function () {
        show(current + 1);
    });

    // 背景（画像以外）をクリックしたら閉じる。
    // ただし動画の再生中は、画面のどこを触っても閉じないようにする
    // （閉じるのは「閉じる」ボタンか Esc キーだけ）。
    lightbox.addEventListener('click', function (event) {
        if (showingVideo) {
            return;
        }

        if (event.target === lightbox || event.target.classList.contains('lb-figure')) {
            close();
        }
    });

    // 動画を見ているあいだは、ダブルクリック（ダブルタップ）で
    // 「元の大きさ」と「画面に合わせて最大化」を切り替える。
    // 切り替えた内容は、設定画面で選んだときと同じように残る。
    lightbox.addEventListener('dblclick', function (event) {
        if (!showingVideo) {
            return;
        }

        var target = event.target;

        // 「閉じる」「前へ」「次へ」を続けて押したときは切り替えない
        if (target && target.closest && target.closest('button')) {
            return;
        }

        // 映像の下のほうは、再生バーや音量など、プレーヤー自身の操作の場所。
        // ここでの二度押しは切り替えに使わない。
        if (target === video) {
            var box = video.getBoundingClientRect();

            if (event.clientY > box.bottom - 60) {
                return;
            }
        }

        // ブラウザによっては、動画のダブルクリックが全画面表示や早送りに
        // 割り当てられている。そちらは動かないようにしておく。
        event.preventDefault();

        changeSetting(function () {
            settings.videoSize = settings.videoSize === 'fit' ? 'original' : 'fit';
        });
    });

    // ---- キーボード操作 --------------------------------------------

    document.addEventListener('keydown', function (event) {
        if (lightbox.hidden) {
            return;
        }

        // Esc はどちらでも閉じる。Backspace は文字を消すつもりで押しがちなので、
        // 動画のときは効かせない（見ている途中で閉じてしまわないように）。
        if (event.key === 'Escape' || (event.key === 'Backspace' && !showingVideo)) {
            close();
            return;
        }

        // 動画では矢印キーやスペースキーはシーク・再生の操作なので、
        // めくる操作には使わない（プレーヤーにそのまま渡す）。
        if (showingVideo) {
            return;
        }

        switch (event.key) {
            case 'ArrowLeft':
                show(current - 1);
                break;
            case 'ArrowRight':
            case ' ':
                event.preventDefault();
                show(current + 1);
                break;
            case 'Home':
                show(0);
                break;
            case 'End':
                show(thumbs.length - 1);
                break;
        }
    });

    // ---- スワイプ操作（スマートフォン・タブレット向け） --------------

    var touchStartX = 0;
    var touchStartY = 0;

    // 今のタッチをめくる操作として扱ってよいか。
    // 指を2本使ったとき（ピンチ）や拡大中は false にして、誤って隣の画像へ
    // 移ってしまうのを防ぐ。
    var swipeReady = false;

    // ピンチで拡大されているかどうか。拡大中は指を動かして画像の見たい場所へ
    // 寄せる操作なので、めくる操作と混同しない。
    function isZoomed() {
        var viewport = window.visualViewport;
        return !!viewport && viewport.scale > 1.01;
    }

    lightbox.addEventListener('touchstart', function (event) {
        if (event.touches.length !== 1 || isZoomed() || showingVideo) {
            swipeReady = false;
            return;
        }

        swipeReady = true;
        touchStartX = event.changedTouches[0].clientX;
        touchStartY = event.changedTouches[0].clientY;
    }, { passive: true });

    // 1本指で始めた後に2本目が触れた場合も、そこからはピンチ操作とみなす
    lightbox.addEventListener('touchmove', function (event) {
        if (event.touches.length > 1 || isZoomed()) {
            swipeReady = false;
        }
    }, { passive: true });

    lightbox.addEventListener('touchcancel', function () {
        swipeReady = false;
    }, { passive: true });

    lightbox.addEventListener('touchend', function (event) {
        // event.touches は画面に残っている指。2本のうち1本を離しただけなら何もしない
        var done = swipeReady && event.touches.length === 0 && !isZoomed();

        swipeReady = false;

        if (!done) {
            return;
        }

        var deltaX = event.changedTouches[0].clientX - touchStartX;
        var deltaY = event.changedTouches[0].clientY - touchStartY;

        // 横方向の動きが縦より大きいときだけ、めくる操作とみなす
        if (Math.abs(deltaX) > 50 && Math.abs(deltaX) > Math.abs(deltaY)) {
            show(deltaX < 0 ? current + 1 : current - 1);
        }
    }, { passive: true });

    // ---- リスト表示で行を押したとき ----------------------------------
    //
    // リストでは行のどこを押しても拡大・再生できるようにする。
    // サムネイル（.thumb）自身は自前の処理を持っているので、そちらに任せる。

    var grid = document.getElementById('grid');

    if (grid) {
        grid.addEventListener('click', function (event) {
            var target = event.target;

            if (document.body.dataset.view !== 'list' ||
                document.body.classList.contains('selecting')) {
                return;
            }

            if (!target || !target.closest ||
                target.closest('.select-box') || target.closest('.thumb')) {
                return;
            }

            var card = target.closest('.card');
            var thumb = card ? card.querySelector('.thumb') : null;
            var index = thumb ? thumbs.indexOf(thumb) : -1;

            if (index >= 0) {
                event.preventDefault();
                open(index);
            }
        });
    }
}());
