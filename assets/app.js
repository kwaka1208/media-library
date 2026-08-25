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

    function closeModal() {
        if (!openedModal) {
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

        select.value = selectable.length > 0 ? selectable[0].value : '';
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

    // ---- フォルダの説明（info.yml）の編集 --------------------------

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

    function showInfo(opener) {
        if (!infoModal) {
            return;
        }

        // 前に開いたときの書きかけは残さず、画面を読み込んだ時点の内容に戻す
        infoRows.innerHTML = infoRowsHtml;

        var form = infoModal.querySelector('form');
        form.reset();

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
                '.detail-panel, .modal, .context-menu, .lightbox')) {
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

            if (openedModal || (lightbox && !lightbox.hidden)) {
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

    function menuItem(name) {
        return contextMenu.querySelector('[data-menu="' + name + '"]');
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

        // 意味のある項目だけを出す
        menuItem('rename').hidden = !single;
        menuItem('download').hidden = !(single && !items[0].isFolder);
        menuItem('move').hidden = !onItem;
        menuItem('delete').hidden = !onItem;
        menuItem('mkdir').hidden = onItem;
        menuItem('info').hidden = onItem;
        menuItem('select').hidden = onItem;
        menuItem('select').textContent =
            document.body.classList.contains('selecting') ? '選択をやめる' : '複数選択';

        label.hidden = !onItem;
        label.textContent = onItem ? describe(items) : '';

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

            event.preventDefault();
            openContextMenu(event.clientX, event.clientY, container ? targetsFor(container) : []);
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
                suppressClick = true;
                openContextMenu(touch.clientX, touch.clientY, container ? targetsFor(container) : []);
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

        // 「キャンセル」ボタンと、パネルの外側のクリックで閉じる
        if (target.closest('[data-modal-close]') || (openedModal && target === openedModal)) {
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

        // 操作用のモーダルを開いている間は移動しない
        if (openedModal) {
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
    // 詳細パネルをヘッダーのすぐ下に貼り付けるために、実際の高さを CSS へ渡す。
    // パンくずが折り返すなどで高さが変わるので、幅が変わったら測り直す。

    var pageHeader = document.querySelector('.page-header');

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

    // フォーカスを移したものが、貼り付いたヘッダーや画面の外に隠れないようにする
    function focusItem(element) {
        element.focus({ preventScroll: true });

        var box = element.getBoundingClientRect();
        var headerBottom = pageHeader ? pageHeader.offsetHeight : 0;
        var margin = 8;

        if (box.top < headerBottom + margin) {
            window.scrollBy(0, box.top - headerBottom - margin);
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

        // 拡大表示・操作モーダル・操作メニューを開いているあいだは、そちらに任せる
        if (openedModal || (lightbox && !lightbox.hidden) ||
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

            // リスト表示のときは、行を押したものとして詳細パネル側で受け取る
            if (document.body.dataset.view === 'list') {
                return;
            }

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

    // ---- リスト表示の詳細パネル ------------------------------------
    //
    // リストの行を押すと、右（画面が狭いときは下）に詳細を出す。
    // 押しただけでは拡大しないので、続けて他の行を押して見比べられる。
    // 拡大・再生は、パネルのボタン／サムネイル／行のダブルクリックから。

    var detailPanel = document.getElementById('detailPanel');
    var grid = document.getElementById('grid');

    if (detailPanel && grid) {
        var detailThumb = detailPanel.querySelector('[data-detail-thumb]');
        var detailName  = detailPanel.querySelector('[data-detail-name]');
        var detailKind  = detailPanel.querySelector('[data-detail-kind]');
        var detailSize  = detailPanel.querySelector('[data-detail-size]');
        var detailDate  = detailPanel.querySelector('[data-detail-date]');
        var detailPath  = detailPanel.querySelector('[data-detail-path]');
        var detailOpen  = detailPanel.querySelector('[data-act="detail-open"]');

        // いま詳細を出している行
        var detailCard = null;

        function isListView() {
            return document.body.dataset.view === 'list';
        }

        // 行から、ライトボックスの何番目にあたるかを求める
        function indexOfCard(card) {
            var thumb = card ? card.querySelector('.thumb') : null;

            return thumb ? thumbs.indexOf(thumb) : -1;
        }

        function markCurrent(card) {
            if (detailCard) {
                detailCard.classList.remove('current');
            }

            detailCard = card;

            if (detailCard) {
                detailCard.classList.add('current');
            }
        }

        // パネルのサムネイル。動画は一覧と同じく、先頭付近の1コマを見せる。
        function fillThumb(card) {
            var node;

            detailThumb.textContent = '';

            if (card.dataset.kind === 'video') {
                node = document.createElement('video');
                node.preload = 'metadata';
                node.muted = true;
                node.playsInline = true;
                node.tabIndex = -1;

                // 一覧のサムネイルと同じく、先頭付近の1コマを見せる
                node.src = card.dataset.src + '#t=0.1';

                detailThumb.appendChild(node);

                // 後から作った要素は、読み込みを促さないと1コマも出ないことがある
                node.load();

                return;
            }

            node = document.createElement('img');
            node.src = card.dataset.src;
            node.alt = card.dataset.name || '';
            node.decoding = 'async';

            detailThumb.appendChild(node);
        }

        function showDetail(card) {
            var isVideo = card.dataset.kind === 'video';
            var path = card.dataset.path || '';
            var slash = path.lastIndexOf('/');

            fillThumb(card);

            detailName.textContent = card.dataset.name || '';
            detailKind.textContent = isVideo ? '動画' : '写真';
            detailSize.textContent = card.dataset.size || '';
            detailDate.textContent = card.dataset.date || '';
            detailPath.textContent = slash === -1 ? 'ホーム' : path.slice(0, slash);
            detailOpen.textContent = isVideo ? '再生する' : '拡大表示';

            markCurrent(card);

            detailPanel.hidden = false;
            document.body.classList.add('detail-open');

            // 画面が狭いときは、詳細が画面の下から出てきて、押した行を隠すことがある。
            // 隠れているときだけ、その行が見える位置まで動かす。
            if (window.matchMedia('(max-width: 760px)').matches) {
                revealCard(card);
            }
        }

        // 押した行が、下から出た詳細や貼り付いたヘッダーに隠れないようにする
        function revealCard(card) {
            var box = card.getBoundingClientRect();
            var panelTop = window.innerHeight - detailPanel.offsetHeight;
            var headerBottom = pageHeader ? pageHeader.offsetHeight : 0;
            var margin = 8;

            // なめらかな動き（behavior: 'smooth'）は、動きを減らす設定の環境では
            // 何も起きないことがあるので、そのまま動かす。
            if (box.bottom > panelTop - margin) {
                window.scrollBy(0, box.bottom - panelTop + margin);
            } else if (box.top < headerBottom + margin) {
                window.scrollBy(0, box.top - headerBottom - margin);
            }
        }

        function closeDetail() {
            detailPanel.hidden = true;
            document.body.classList.remove('detail-open');

            // 中身を消して、見えないところで動画の読み込みが続かないようにする
            detailThumb.textContent = '';

            markCurrent(null);
        }

        function openCurrent() {
            var index = indexOfCard(detailCard);

            if (index >= 0) {
                open(index);
            }
        }

        // 行を押したら詳細を出す。選択モード中と、チェックボックスの上は除く。
        grid.addEventListener('click', function (event) {
            var target = event.target;

            if (!isListView() || document.body.classList.contains('selecting')) {
                return;
            }

            if (!target || !target.closest || target.closest('.select-box')) {
                return;
            }

            var card = target.closest('.card');

            if (card) {
                event.preventDefault();
                showDetail(card);
            }
        });

        // 二度押しで、そのまま拡大・再生する
        grid.addEventListener('dblclick', function (event) {
            var target = event.target;

            if (!isListView() || document.body.classList.contains('selecting')) {
                return;
            }

            if (!target || !target.closest || target.closest('.select-box')) {
                return;
            }

            var card = target.closest('.card');

            if (card) {
                event.preventDefault();
                markCurrent(card);
                openCurrent();
            }
        });

        detailPanel.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-act]');

            if (!trigger) {
                return;
            }

            switch (trigger.dataset.act) {
                case 'detail-close':
                    closeDetail();
                    break;
                case 'detail-open':
                    openCurrent();
                    break;
                case 'detail-download':
                    if (detailCard) {
                        download(detailCard.dataset.src, detailCard.dataset.name);
                    }
                    break;
            }
        });

        // パネルのサムネイルからも開けるようにする
        detailThumb.addEventListener('click', openCurrent);

        // Esc で閉じる。ただし、先に閉じるものがあるときは譲る。
        //
        // 他の Esc の処理より先に判断したいので、捕まえる側（capture）で受け取る。
        // 後回しにすると、拡大表示を閉じた直後の「もう何も開いていない」状態を見て、
        // 詳細まで一緒に閉じてしまう。
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || detailPanel.hidden) {
                return;
            }

            if (openedModal || !lightbox.hidden) {
                return;
            }

            if (contextMenu && !contextMenu.hidden) {
                return;
            }

            if (document.body.classList.contains('selecting')) {
                return;
            }

            closeDetail();
        }, true);
    }
}());
