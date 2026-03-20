/* global EHMEP, jQuery */
(function ($) {
  'use strict';

  var currentMenuId = 0;
  var menuItems = [];
  var deletedIds = [];

  /* ---------------------------------------------------------------
     Init
  --------------------------------------------------------------- */
  $(function () {
    // Auto-open on standalone admin page (data-post-id="0")
    var $autoOpen = $('#ehmep-auto-open');
    if ($autoOpen.length && parseInt($autoOpen.data('post-id'), 10) === 0) {
      openModal();
    }

    // Open via metabox button
    $(document).on('click', '#ehmep-open-editor', function () {
      openModal();
    });

    // Close via backdrop or X button
    $(document).on('click', '#ehmep-modal-close, #ehmep-modal-x', function () {
      closeModal();
    });

    // Keyboard close
    $(document).on('keydown', function (e) {
      if (e.key === 'Escape') { closeModal(); }
    });

    // Menu dropdown change
    $(document).on('change', '#ehmep-menu-select', function () {
      currentMenuId = parseInt($(this).val(), 10);
      loadMenuItems(currentMenuId);
    });

    // Add current post to menu
    $(document).on('click', '#ehmep-add-current-post', function () {
      var postId = parseInt($('#ehmep-open-editor').data('post-id') || 0, 10);
      addCurrentPost(postId);
    });

    // "Is parent" checkbox disables URL + target
    $(document).on('change', '#ehmep-custom-is-parent', function () {
      if ($(this).is(':checked')) {
        $('#ehmep-custom-url').val('#').prop('disabled', true);
        $('#ehmep-custom-target').prop('checked', false).prop('disabled', true);
      } else {
        $('#ehmep-custom-url').val('').prop('disabled', false);
        $('#ehmep-custom-target').prop('disabled', false);
      }
    });

    // Add custom link
    $(document).on('click', '#ehmep-add-custom-link', function () {
      addCustomLink();
    });

    // Save menu
    $(document).on('click', '#ehmep-save-menu', function () {
      saveMenu();
    });
  });

  /* ---------------------------------------------------------------
     Modal helpers
  --------------------------------------------------------------- */
  function openModal() {
    var $modal = $('#ehmep-modal');
    $modal.attr('aria-hidden', 'false').addClass('is-open');
    $('body').addClass('ehmep-modal-open');
    loadMenuData();
  }

  function closeModal() {
    $('#ehmep-modal').attr('aria-hidden', 'true').removeClass('is-open');
    $('body').removeClass('ehmep-modal-open');
  }

  function setStatus(msg, isError) {
    var $s = $('#ehmep-status');
    $s.text(msg).removeClass('ehmep-status--ok ehmep-status--error');
    if (isError) {
      $s.addClass('ehmep-status--error');
    } else if (msg) {
      $s.addClass('ehmep-status--ok');
    }
  }

  /* ---------------------------------------------------------------
     AJAX: load all menus + items for first/active menu
  --------------------------------------------------------------- */
  function loadMenuData() {
    setStatus(EHMEP.strings.loading);
    $.post(EHMEP.ajaxUrl, {
      action: 'ehmep_get_menu_data',
      nonce:  EHMEP.nonce,
      menu_id: currentMenuId || 0
    })
    .done(function (resp) {
      if (!resp.success) { setStatus(EHMEP.strings.error, true); return; }
      var data = resp.data;
      currentMenuId = data.activeMenuId;
      populateMenuSelect(data.menus, data.activeMenuId);
      menuItems = data.items;
      deletedIds = [];
      renderTree();
      setStatus('');
    })
    .fail(function () { setStatus(EHMEP.strings.error, true); });
  }

  /* ---------------------------------------------------------------
     AJAX: reload items after menu change
  --------------------------------------------------------------- */
  function loadMenuItems(menuId) {
    setStatus(EHMEP.strings.loading);
    $.post(EHMEP.ajaxUrl, {
      action: 'ehmep_get_menu_data',
      nonce:  EHMEP.nonce,
      menu_id: menuId
    })
    .done(function (resp) {
      if (!resp.success) { setStatus(EHMEP.strings.error, true); return; }
      menuItems = resp.data.items;
      deletedIds = [];
      renderTree();
      setStatus('');
    })
    .fail(function () { setStatus(EHMEP.strings.error, true); });
  }

  /* ---------------------------------------------------------------
     Populate menu <select>
  --------------------------------------------------------------- */
  function populateMenuSelect(menus, activeId) {
    var $sel = $('#ehmep-menu-select').empty();
    $.each(menus, function (i, m) {
      $('<option>').val(m.term_id).text(m.name)
        .prop('selected', m.term_id === activeId)
        .appendTo($sel);
    });
  }

  /* ---------------------------------------------------------------
     Build + render the menu tree
  --------------------------------------------------------------- */
  function renderTree() {
    var $root = $('#ehmep-menu-tree').empty();

    // Recursively append children
    function appendChildren($container, parentId) {
      var children = menuItems.filter(function (it) {
        return it.menu_item_parent === parentId;
      });
      children.forEach(function (it) {
        var $li = buildItemLi(it);
        var $childUl = $('<ul class="ehmep-children">');
        appendChildren($childUl, it.ID);
        if ($childUl.children().length) {
          $li.addClass('has-children').append($childUl);
          initSortable($childUl);
        }
        $container.append($li);
      });
    }

    appendChildren($root, 0);
    initSortable($root);
    rebuildParentSelect();
  }

  /* ---------------------------------------------------------------
     Build a single <li> for one menu item
  --------------------------------------------------------------- */
  function buildItemLi(item) {
    var isOrphan   = item.orphaned === 1;
    var typeLabel  = typeToLabel(item.type);
    var targetChk  = item.target === '_blank' ? ' checked' : '';

    var rolesHtml = '';
    if (EHMEP.roles && EHMEP.roles.length) {
      rolesHtml += '<div class="ehmep-item-roles"><span class="ehmep-roles-label">' +
        '<strong>Rollen-Sichtbarkeit</strong> <em>(leer = alle)</em></span>';
      EHMEP.roles.forEach(function (role) {
        var chk = item.roles && item.roles.indexOf(role.slug) !== -1 ? ' checked' : '';
        rolesHtml +=
          '<label class="ehmep-inline">' +
          '<input type="checkbox" class="ehmep-role-check" data-role="' + escAttr(role.slug) + '"' + chk + ' /> ' +
          escHtml(role.name) +
          '</label>';
      });
      rolesHtml += '</div>';
    }

    var orphanHtml = isOrphan
      ? '<span class="ehmep-orphan-warning" title="Post gelöscht oder im Papierkorb">&#9888; Verwaist</span>'
      : '';

    var urlReadonly = item.type !== 'custom' ? ' readonly' : '';

    var $li = $(
      '<li class="ehmep-item' + (isOrphan ? ' is-orphan' : '') + '"' +
          ' data-id="' + item.ID + '"' +
          ' data-parent="' + item.menu_item_parent + '"' +
          ' data-type="' + escAttr(item.type) + '">' +

        '<div class="ehmep-item-header">' +
          '<span class="ehmep-drag-handle dashicons dashicons-menu" title="Verschieben"></span>' +
          '<span class="ehmep-toggle" title="Details ein-/ausklappen">&#9658;</span>' +
          '<span class="ehmep-item-type-badge">' + typeLabel + '</span>' +
          '<input type="text" class="ehmep-item-title" value="' + escAttr(item.title) + '" aria-label="Titel" />' +
          orphanHtml +
          '<button type="button" class="ehmep-delete-item" title="Entfernen" aria-label="Entfernen">&times;</button>' +
        '</div>' +

        '<div class="ehmep-item-details">' +
          '<div class="ehmep-item-field">' +
            '<label>URL</label>' +
            '<input type="text" class="ehmep-item-url" value="' + escAttr(item.url) + '"' + urlReadonly + ' />' +
          '</div>' +
          '<div class="ehmep-item-field">' +
            '<label>Beschreibung</label>' +
            '<textarea class="ehmep-item-desc" rows="2">' + escHtml(item.description) + '</textarea>' +
          '</div>' +
          '<div class="ehmep-item-field">' +
            '<label class="ehmep-inline">' +
              '<input type="checkbox" class="ehmep-item-target"' + targetChk + ' /> In neuem Tab öffnen' +
            '</label>' +
          '</div>' +
          rolesHtml +
        '</div>' +
      '</li>'
    );

    // Toggle details panel
    $li.find('.ehmep-toggle').on('click', function () {
      $(this).toggleClass('is-open');
      $li.find('> .ehmep-item-details').slideToggle(150);
    });

    // Delete item
    $li.find('.ehmep-delete-item').on('click', function () {
      if (window.confirm('Menüeintrag wirklich entfernen?')) {
        deletedIds.push(item.ID);
        $li.remove();
        rebuildParentSelect();
      }
    });

    return $li;
  }

  function typeToLabel(type) {
    if (type === 'custom')   { return 'Link'; }
    if (type === 'taxonomy') { return 'Kategorie'; }
    return 'Seite/Beitrag';
  }

  /* ---------------------------------------------------------------
     jQuery UI Sortable init (nested)
  --------------------------------------------------------------- */
  function initSortable($ul) {
    $ul.sortable({
      handle:      '.ehmep-drag-handle',
      items:       '> li.ehmep-item',
      connectWith: '.ehmep-menu-tree, .ehmep-children',
      placeholder: 'ehmep-sortable-placeholder',
      tolerance:   'pointer',
      update: function () {
        rebuildParentSelect();
      }
    });
  }

  /* ---------------------------------------------------------------
     Rebuild parent <select> from current DOM order
  --------------------------------------------------------------- */
  function rebuildParentSelect() {
    var $sel = $('#ehmep-parent-select');
    $sel.find('option:not(:first)').remove();
    $('#ehmep-menu-tree > li.ehmep-item').each(function () {
      var id    = $(this).data('id');
      var title = $(this).find('> .ehmep-item-header .ehmep-item-title').val() || '(ohne Titel)';
      $('<option>').val(id).text('\u2014 ' + title).appendTo($sel);
    });
  }

  /* ---------------------------------------------------------------
     AJAX: add current post
  --------------------------------------------------------------- */
  function addCurrentPost(postId) {
    if (!postId || !currentMenuId) {
      window.alert('Kein Beitrag oder Menü ausgewählt.');
      return;
    }
    var parentId = parseInt($('#ehmep-parent-select').val(), 10) || 0;
    setStatus(EHMEP.strings.loading);
    $.post(EHMEP.ajaxUrl, {
      action:    'ehmep_add_current_post_to_menu',
      nonce:     EHMEP.nonce,
      post_id:   postId,
      menu_id:   currentMenuId,
      parent_id: parentId
    })
    .done(function (resp) {
      if (!resp.success) { setStatus(EHMEP.strings.error, true); return; }
      setStatus('Hinzugefügt!');
      loadMenuItems(currentMenuId);
    })
    .fail(function () { setStatus(EHMEP.strings.error, true); });
  }

  /* ---------------------------------------------------------------
     AJAX: add custom link
  --------------------------------------------------------------- */
  function addCustomLink() {
    var title    = $('#ehmep-custom-title').val().trim();
    var url      = $('#ehmep-custom-url').val().trim();
    var desc     = $('#ehmep-custom-desc').val().trim();
    var target   = $('#ehmep-custom-target').is(':checked') ? 1 : 0;
    var isParent = $('#ehmep-custom-is-parent').is(':checked');
    var parentId = parseInt($('#ehmep-parent-select').val(), 10) || 0;

    if (isParent) { url = '#'; target = 0; }

    if (!title || !url) {
      window.alert('Bitte Anzeigetext und URL eingeben.');
      return;
    }

    setStatus(EHMEP.strings.loading);
    $.post(EHMEP.ajaxUrl, {
      action:      'ehmep_add_custom_link_to_menu',
      nonce:       EHMEP.nonce,
      menu_id:     currentMenuId,
      parent_id:   parentId,
      title:       title,
      url:         url,
      description: desc,
      target:      target
    })
    .done(function (resp) {
      if (!resp.success) { setStatus(EHMEP.strings.error, true); return; }
      setStatus('Hinzugefügt!');
      $('#ehmep-custom-title').val('');
      $('#ehmep-custom-url').val('').prop('disabled', false);
      $('#ehmep-custom-desc').val('');
      $('#ehmep-custom-target').prop('checked', false).prop('disabled', false);
      $('#ehmep-custom-is-parent').prop('checked', false);
      loadMenuItems(currentMenuId);
    })
    .fail(function () { setStatus(EHMEP.strings.error, true); });
  }

  /* ---------------------------------------------------------------
     Collect items from current DOM tree
  --------------------------------------------------------------- */
  function collectItemsFromDom() {
    var items = [];

    function walkList($ul, parentId) {
      $ul.children('li.ehmep-item').each(function () {
        var $li     = $(this);
        var id      = parseInt($li.data('id'), 10);
        var title   = $li.find('> .ehmep-item-header .ehmep-item-title').val() || '';
        var url     = $li.find('> .ehmep-item-details .ehmep-item-url').val() || '';
        var desc    = $li.find('> .ehmep-item-details .ehmep-item-desc').val() || '';
        var target  = $li.find('> .ehmep-item-details .ehmep-item-target').is(':checked') ? '_blank' : '';
        var type    = $li.data('type') || '';
        var roles   = [];

        $li.find('> .ehmep-item-details .ehmep-role-check:checked').each(function () {
          roles.push($(this).data('role'));
        });

        items.push({ ID: id, parent: parentId, title: title, url: url,
                     description: desc, target: target, type: type, roles: roles });

        var $childUl = $li.children('.ehmep-children');
        if ($childUl.length) { walkList($childUl, id); }
      });
    }

    walkList($('#ehmep-menu-tree'), 0);

    // Append deleted items
    deletedIds.forEach(function (id) {
      items.push({ ID: id, deleted: 1 });
    });

    return items;
  }

  /* ---------------------------------------------------------------
     AJAX: save menu
  --------------------------------------------------------------- */
  function saveMenu() {
    if (!currentMenuId) { window.alert('Kein Menü ausgewählt.'); return; }
    var items = collectItemsFromDom();
    setStatus(EHMEP.strings.loading);
    $.post(EHMEP.ajaxUrl, {
      action:  'ehmep_save_menu',
      nonce:   EHMEP.nonce,
      menu_id: currentMenuId,
      payload: JSON.stringify({ items: items })
    })
    .done(function (resp) {
      if (!resp.success) { setStatus(EHMEP.strings.error, true); return; }
      setStatus(EHMEP.strings.saved);
      deletedIds = [];
    })
    .fail(function () { setStatus(EHMEP.strings.error, true); });
  }

  /* ---------------------------------------------------------------
     Escape helpers
  --------------------------------------------------------------- */
  function escAttr(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

}(jQuery));
