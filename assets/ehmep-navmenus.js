/* global jQuery */
/**
 * ehmep-navmenus.js
 * Marks orphaned menu items in the native WordPress nav-menus.php editor.
 * Orphan flags are rendered by Eifelhoster_Menu_Editor_Pro::render_orphan_marker_field().
 */
(function ($) {
  'use strict';

  function markOrphans() {
    $('.ehmep-orphan-flag').each(function () {
      if ($(this).attr('data-ehmep-orphan') === '1') {
        var $item = $(this).closest('.menu-item');
        $item.addClass('ehmep-orphaned-item');
        if (!$item.find('.ehmep-orphan-notice').length) {
          $item.find('.item-title').after(
            '<span class="ehmep-orphan-notice">' +
            '&#9888; Verwaist – Original-Post gelöscht / im Papierkorb' +
            '</span>'
          );
        }
      }
    });
  }

  $(function () {
    markOrphans();

    // Re-run after any AJAX call (e.g. adding new items via native WP panel)
    $(document).ajaxComplete(function () {
      markOrphans();
    });
  });

}(jQuery));
