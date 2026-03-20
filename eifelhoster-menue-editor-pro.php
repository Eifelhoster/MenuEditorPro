<?php
/**
 * Plugin Name: Eifelhoster-Menü-Editor Pro
 * Description: Menüeditor (Sidebar + Admin-Seite) mit eigenem Speichern, Drag&Drop, Custom Links, Feldern (Anzeigetext, Beschreibung, Target) sowie Rollen-Sichtbarkeit und Auto-Cleanup bei Post-Löschung/Papierkorb.
 * Version: 2.2.1
 * Author: eifelhoster.de – Michael Krämer
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

class Eifelhoster_Menu_Editor_Pro {
  const NONCE_ACTION = 'ehmep_nonce_action';
  const META_ROLES   = '_ehmep_visible_roles';

  private $admin_page_hook = '';

  public function __construct() {
    add_action('admin_menu', [$this, 'register_admin_menu']);
    add_action('add_meta_boxes', [$this, 'register_metabox']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

    add_action('wp_ajax_ehmep_get_menu_data', [$this, 'ajax_get_menu_data']);
    add_action('wp_ajax_ehmep_save_menu',     [$this, 'ajax_save_menu']);
    add_action('wp_ajax_ehmep_add_current_post_to_menu', [$this, 'ajax_add_current_post_to_menu']);
    add_action('wp_ajax_ehmep_add_custom_link_to_menu',  [$this, 'ajax_add_custom_link_to_menu']);

    add_filter('wp_get_nav_menu_items', [$this, 'filter_menu_items_by_role'], 10, 3);

    // Menüeinträge entfernen beim Papierkorb + beim endgültigen Löschen
    add_action('wp_trash_post',        [$this, 'cleanup_menu_items_on_post_trash'], 10, 1);
    add_action('before_delete_post',   [$this, 'cleanup_menu_items_on_post_delete'], 10, 1);

    // Verwaiste Markierung im WP-Menüeditor
    add_filter('wp_setup_nav_menu_item', [$this, 'mark_orphan_on_setup_nav_menu_item']);
    add_action('wp_nav_menu_item_custom_fields', [$this, 'render_orphan_marker_field'], 10, 4);
  }

  public function register_admin_menu() {
    $this->admin_page_hook = add_menu_page(
      'EH-Menü',
      'EH-Menü',
      'edit_theme_options',
      'eh-menue',
      [$this, 'render_admin_page'],
      'dashicons-list-view',
      58
    );
  }

  public function render_admin_page() {
    if (!current_user_can('edit_theme_options')) {
      echo '<div class="wrap"><h1>EH-Menü</h1><p>Keine Berechtigung.</p></div>';
      return;
    }
    wp_nonce_field(self::NONCE_ACTION, self::NONCE_ACTION);

    echo '<div class="wrap">';
    echo '<h1>EH-Menü</h1>';
    // Marker: Auto-Open ohne Beitrags-Kontext
    echo '<div id="ehmep-auto-open" data-post-id="0"></div>';
    $this->render_modal(false);
    echo '</div>';
  }

  public function register_metabox() {
    $post_types = get_post_types(['public' => true], 'names');
    foreach ($post_types as $pt) {
      add_meta_box(
        'ehmep_metabox',
        'Eifelhoster-Menü-Editor Pro',
        [$this, 'render_metabox'],
        $pt,
        'side',
        'high'
      );
    }
  }

  public function render_metabox($post) {
    if (!current_user_can('edit_theme_options')) {
      echo '<p>Keine Berechtigung (edit_theme_options erforderlich).</p>';
      return;
    }
    wp_nonce_field(self::NONCE_ACTION, self::NONCE_ACTION);

    echo '<p><button type="button" class="button button-primary" id="ehmep-open-editor" data-post-id="' . esc_attr($post->ID) . '">Menü-Editor öffnen</button></p>';
    echo '<p class="description">Speichern erfolgt ausschließlich im Menü-Editor (eigener Speichern-Button).</p>';

    $this->render_modal(true);
  }

  private function render_modal($show_current_post_block) {
?>
<div id="ehmep-modal" class="ehmep-modal" aria-hidden="true">
  <div class="ehmep-modal__backdrop" id="ehmep-modal-close"></div>
  <div class="ehmep-modal__panel" role="dialog" aria-modal="true" aria-labelledby="ehmep-modal-title">
    <div class="ehmep-modal__header">
    <div class="ehmep-header-left">
      <h2 id="ehmep-modal-title">Eifelhoster-Menü-Editor Pro</h2>
      <a class="ehmep-header-logo" href="https://eifelhoster.de" target="_blank" rel="noopener">
        <img src="<?php echo esc_url( plugin_dir_url(__FILE__) . 'assets/eifelhoster-logo.png' ); ?>" alt="eifelhoster.de" />
      </a>
    </div>
    <button type="button" class="button" id="ehmep-modal-x">Schließen</button>
    </div>

    <div class="ehmep-modal__body">
      <div class="ehmep-grid">
        <div class="ehmep-card">
          <h3>Menü wählen</h3>
          <div class="ehmep-row">
            <label for="ehmep-menu-select"><strong>Menü</strong></label>
            <select id="ehmep-menu-select"></select>
          </div>

          <hr/>

          <h3>Einfügen</h3>
          <div class="ehmep-row">
            <label for="ehmep-parent-select"><strong>Parent (Position)</strong></label>
            <select id="ehmep-parent-select">
              <option value="0">— Oberste Ebene —</option>
            </select>
          </div>

          <?php if ($show_current_post_block): ?>
          <div class="ehmep-row">
            <button type="button" class="button button-secondary" id="ehmep-add-current-post">Aktuellen Beitrag ins Menü einfügen</button>
          </div>
          <?php endif; ?>

          <hr/>

          <h3>Einfügen: Custom Link</h3>
          <div class="ehmep-row">
            <label for="ehmep-custom-title"><strong>Anzeigetext</strong></label>
            <input type="text" id="ehmep-custom-title" placeholder="z.B. Kontakt" />
          </div>
          <div class="ehmep-row">
            <label for="ehmep-custom-url"><strong>URL</strong></label>
            <input type="url" id="ehmep-custom-url" placeholder="https://example.com" />
          </div>
          <div class="ehmep-row">
            <label class="ehmep-inline"><input type="checkbox" id="ehmep-custom-is-parent" /> Als Obermenü (URL = #, kein Target)</label>
          </div>
          <div class="ehmep-row">
            <label class="ehmep-inline"><input type="checkbox" id="ehmep-custom-target" /> In neuem Tab öffnen (Target)</label>
          </div>
          <div class="ehmep-row">
            <label for="ehmep-custom-desc"><strong>Beschreibung</strong></label>
            <textarea id="ehmep-custom-desc" rows="3" placeholder="Optional…"></textarea>
          </div>
          <div class="ehmep-row">
            <button type="button" class="button" id="ehmep-add-custom-link">Custom Link hinzufügen</button>
          </div>
        </div>

        <div class="ehmep-card">
          <h3>Menüstruktur</h3>
          <p class="description">Standardmäßig eingeklappt. Untermenüs sind ausgeblendet und werden über Pfeil-Indicator sichtbar gemacht. Drag&Drop zum Verschieben.</p>

          <div id="ehmep-menu-tree-wrap">
            <ul id="ehmep-menu-tree" class="ehmep-menu-tree"></ul>
          </div>

          <div class="ehmep-actions">
            <button type="button" class="button button-primary" id="ehmep-save-menu">Menü speichern</button>
            <span id="ehmep-status" class="ehmep-status" aria-live="polite"></span>
          </div>
        </div>
      </div>
    </div>

    <div class="ehmep-modal__footer">
      <small>Kompatibel mit dem Standard-Menüeditor. Rollen-Sichtbarkeit wird zusätzlich gespeichert.</small>
    </div>
  </div>
</div>
<?php
  }

  public function enqueue_admin_assets($hook) {
    $is_post_screen   = in_array($hook, ['post.php', 'post-new.php'], true);
    $is_eh_page       = ($hook === $this->admin_page_hook);
    $is_wp_menu_editor = ($hook === 'nav-menus.php');

    if (!$is_post_screen && !$is_eh_page && !$is_wp_menu_editor) return;
    if (!current_user_can('edit_theme_options')) return;

    wp_enqueue_script('jquery-ui-sortable');

    $url = plugin_dir_url(__FILE__);
    wp_enqueue_style('ehmep-admin', $url . 'assets/ehmep-admin.css', [], '2.0.5');
    wp_enqueue_script('ehmep-admin', $url . 'assets/ehmep-admin.js', ['jquery', 'jquery-ui-sortable'], '2.0.5', true);

    if ($is_wp_menu_editor) {
      wp_enqueue_script('ehmep-navmenus', $url . 'assets/ehmep-navmenus.js', ['jquery'], '2.0.5', true);
    }

    wp_localize_script('ehmep-admin', 'EHMEP', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce(self::NONCE_ACTION),
      'roles'   => $this->get_roles_for_ui(),
      'strings' => [
        'loading' => 'Lade…',
        'saved'   => 'Gespeichert.',
        'error'   => 'Fehler.',
      ],
    ]);
  }

  private function verify_ajax() {
    if (!current_user_can('edit_theme_options')) {
      wp_send_json_error(['message' => 'No capability.'], 403);
    }
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['message' => 'Bad nonce.'], 403);
    }
  }

  private function get_roles_for_ui() {
    global $wp_roles;
    if (!$wp_roles) $wp_roles = wp_roles();
    $out = [];
    foreach ($wp_roles->roles as $slug => $role) {
      $out[] = ['slug' => $slug, 'name' => $role['name']];
    }
    return $out;
  }

  private function menu_items_to_array($menu_id) {
    $items = [];
    $raw = wp_get_nav_menu_items($menu_id, ['post_status' => 'publish,draft']);
    $raw = is_array($raw) ? $raw : [];

    foreach ($raw as $it) {
      $roles = get_post_meta($it->ID, self::META_ROLES, true);
      if (!is_array($roles)) $roles = [];

      $orphaned = false;
      if (isset($it->type) && $it->type === 'post_type') {
        $st = get_post_status((int)$it->object_id);
        if (!$st || $st === 'trash') $orphaned = true;
      }

      $items[] = [
        'ID' => (int)$it->ID,
        'menu_item_parent' => (int)$it->menu_item_parent,
        'title' => (string)$it->title,
        'type'  => (string)$it->type,
        'object' => (string)$it->object,
        'object_id' => (int)$it->object_id,
        'url' => (string)$it->url,
        'position' => (int)$it->menu_order,
        'description' => isset($it->description) ? (string)$it->description : '',
        'target' => isset($it->target) ? (string)$it->target : '',
        'roles' => array_values(array_map('sanitize_key', $roles)),
        'orphaned' => $orphaned ? 1 : 0,
      ];
    }
    return $items;
  }

  public function ajax_get_menu_data() {
    $this->verify_ajax();

    $menus = wp_get_nav_menus();
    $menus_out = [];
    foreach ($menus as $m) {
      $menus_out[] = ['term_id' => (int)$m->term_id, 'name' => $m->name];
    }

    $menu_id = isset($_POST['menu_id']) ? (int) $_POST['menu_id'] : 0;
    if ($menu_id <= 0 && !empty($menus_out)) $menu_id = (int) $menus_out[0]['term_id'];

    $items = [];
    if ($menu_id > 0) $items = $this->menu_items_to_array($menu_id);

    wp_send_json_success([
      'menus' => $menus_out,
      'activeMenuId' => $menu_id,
      'items' => $items,
    ]);
  }

  public function ajax_add_current_post_to_menu() {
    $this->verify_ajax();

    $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
    $menu_id = isset($_POST['menu_id']) ? (int) $_POST['menu_id'] : 0;
    $parent  = isset($_POST['parent_id']) ? (int) $_POST['parent_id'] : 0;

    if ($post_id <= 0 || $menu_id <= 0) wp_send_json_error(['message' => 'Missing post_id/menu_id.'], 400);

    $p = get_post($post_id);
    if (!$p) wp_send_json_error(['message' => 'Post not found.'], 404);

    $item_id = wp_update_nav_menu_item($menu_id, 0, [
      'menu-item-object-id' => $post_id,
      'menu-item-object'    => $p->post_type,
      'menu-item-type'      => 'post_type',
      'menu-item-status'    => 'publish',
      'menu-item-title'     => get_the_title($post_id),
      'menu-item-url'       => get_permalink($post_id),
      'menu-item-url'       => get_permalink($post_id),
      'menu-item-parent-id' => $parent,
      'menu-item-description' => '',
      'menu-item-target' => '',
    ]);

    if (is_wp_error($item_id) || !$item_id) wp_send_json_error(['message' => 'Could not add menu item.'], 500);

    // Safety: URL explizit setzen, falls Theme/Setup sonst leere hrefs erzeugt
    update_post_meta((int)$item_id, '_menu_item_url', get_permalink($post_id));

    wp_send_json_success(['item_id' => (int)$item_id]);
  }

  public function ajax_add_custom_link_to_menu() {
    $this->verify_ajax();

    $menu_id = isset($_POST['menu_id']) ? (int) $_POST['menu_id'] : 0;
    $parent  = isset($_POST['parent_id']) ? (int) $_POST['parent_id'] : 0;

    $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
    $url   = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
    $desc  = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
    $target = !empty($_POST['target']) ? '_blank' : '';

    if ($menu_id <= 0 || $title === '' || $url === '') wp_send_json_error(['message' => 'Missing menu_id/title/url.'], 400);

    $item_id = wp_update_nav_menu_item($menu_id, 0, [
      'menu-item-type' => 'custom',
      'menu-item-title' => $title,
      'menu-item-url' => $url,
      'menu-item-status' => 'publish',
      'menu-item-parent-id' => $parent,
      'menu-item-description' => $desc,
      'menu-item-target' => $target,
    ]);

    if (is_wp_error($item_id) || !$item_id) wp_send_json_error(['message' => 'Could not add custom link.'], 500);
    wp_send_json_success(['item_id' => (int)$item_id]);
  }

  public function ajax_save_menu() {
    $this->verify_ajax();

    $menu_id = isset($_POST['menu_id']) ? (int) $_POST['menu_id'] : 0;
    $payload = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
    if ($menu_id <= 0 || empty($payload)) wp_send_json_error(['message' => 'Missing menu_id/payload.'], 400);

    $data = json_decode($payload, true);
    if (!is_array($data)) wp_send_json_error(['message' => 'Invalid JSON.'], 400);

    $items = isset($data['items']) && is_array($data['items']) ? $data['items'] : [];

    foreach ($items as $it) {
      if (!empty($it['deleted']) && !empty($it['ID'])) {
        wp_delete_post((int)$it['ID'], true);
      }
    }

    $order = 1;
    foreach ($items as $it) {
      if (empty($it['ID']) || !empty($it['deleted'])) continue;

      $id = (int)$it['ID'];
      $parent = isset($it['parent']) ? (int)$it['parent'] : 0;
      $title  = isset($it['title']) ? sanitize_text_field($it['title']) : '';
      $desc   = isset($it['description']) ? sanitize_textarea_field($it['description']) : '';
      $target = !empty($it['target']) ? '_blank' : '';
      $type   = isset($it['type']) ? sanitize_key($it['type']) : '';
      $_existing_type  = (string) get_post_meta($id, '_menu_item_type', true);
      $_existing_object = (string) get_post_meta($id, '_menu_item_object', true);
      $_existing_object_id = (int) get_post_meta($id, '_menu_item_object_id', true);
      $_existing_url = (string) get_post_meta($id, '_menu_item_url', true);
      $effective_type = $_existing_type !== '' ? $_existing_type : $type;

      $roles  = isset($it['roles']) && is_array($it['roles']) ? array_values(array_map('sanitize_key', $it['roles'])) : [];

      $args = [
        'menu-item-title'       => $title,
        'menu-item-parent-id'   => $parent,
        'menu-item-position'    => $order,
        'menu-item-status'      => 'publish',
        'menu-item-description' => $desc,
        'menu-item-target'      => $target,
      ];

      // WICHTIG: WP benötigt für bestimmte Typen (post_type/taxonomy/custom) die Kernfelder,
      // sonst können URL/Object-ID verloren gehen (Frontend-Links brechen).
      if ($effective_type === 'custom') {
        $args['menu-item-type'] = 'custom';
        $args['menu-item-url']  = isset($it['url']) && $it['url'] !== '' ? esc_url_raw($it['url']) : $_existing_url;
      } elseif ($effective_type === 'post_type') {
        $args['menu-item-type']      = 'post_type';
        $args['menu-item-object']    = $_existing_object;
        $args['menu-item-object-id'] = $_existing_object_id;
      } elseif ($effective_type === 'taxonomy') {
        $args['menu-item-type']      = 'taxonomy';
        $args['menu-item-object']    = $_existing_object;
        $args['menu-item-object-id'] = $_existing_object_id;
      }

      // Safety: fehlende URL für post_type Items ergänzen (falls leer)
      if ($type !== 'custom') {
        $cur_url = get_post_meta($id, '_menu_item_url', true);
        if (empty($cur_url) && !empty($it['object_id'])) {
          $purl = get_permalink((int)$it['object_id']);
          if ($purl) update_post_meta($id, '_menu_item_url', $purl);
        }
      }

      $res = wp_update_nav_menu_item($menu_id, $id, $args);
      if (is_wp_error($res)) wp_send_json_error(['message' => $res->get_error_message()], 500);

      update_post_meta($id, self::META_ROLES, $roles);
      $order++;
    }

    wp_send_json_success(['message' => 'Saved.']);
  }

  public function filter_menu_items_by_role($items, $menu, $args) {
    if (!is_array($items)) return $items;
    if (is_admin()) return $items;

    $user = wp_get_current_user();
    $user_roles = is_a($user, 'WP_User') ? (array)$user->roles : [];
    $is_logged_in = is_user_logged_in();

    $out = [];
    foreach ($items as $it) {
      $roles = get_post_meta($it->ID, self::META_ROLES, true);
      if (!is_array($roles) || empty($roles)) { $out[] = $it; continue; }
      if ($is_logged_in && array_intersect($user_roles, $roles)) $out[] = $it;
    }
    return $out;
  }

  public function cleanup_menu_items_on_post_trash($post_id) {
    $this->remove_menu_items_pointing_to_post($post_id);
  }

  public function cleanup_menu_items_on_post_delete($post_id) {
    $this->remove_menu_items_pointing_to_post($post_id);
  }

  private function remove_menu_items_pointing_to_post($post_id) {
    $p = get_post($post_id);
    if (!$p) return;

    $q = new WP_Query([
      'post_type'      => 'nav_menu_item',
      'post_status'    => 'any',
      'posts_per_page' => -1,
      'fields'         => 'ids',
      'meta_query'     => [
        ['key' => '_menu_item_object_id','value' => (string)$post_id],
        ['key' => '_menu_item_type','value' => 'post_type'],
        ['key' => '_menu_item_object','value' => $p->post_type],
      ],
    ]);

    if (!empty($q->posts)) {
      foreach ($q->posts as $menu_item_id) {
        wp_delete_post((int)$menu_item_id, true);
      }
    }
  }

  public function mark_orphan_on_setup_nav_menu_item($menu_item) {
    // Für Frontend & Admin: URL sicherstellen + verwaist markieren (post_type)
    if (isset($menu_item->type) && $menu_item->type === 'post_type') {
      $st = get_post_status((int)$menu_item->object_id);
      $is_orphan = (!$st || $st === 'trash') ? 1 : 0;

      if (is_admin()) {
        $menu_item->ehmep_orphaned = $is_orphan;
      }

      // Wenn WP aus irgendeinem Grund keine URL geliefert hat, hier reparieren
      if (!$is_orphan && (empty($menu_item->url) || $menu_item->url === '#')) {
        $menu_item->url = get_permalink((int)$menu_item->object_id);
      }
    }
    return $menu_item;
  }

  public function render_orphan_marker_field($item_id, $item, $depth, $args) {
    $orphan = !empty($item->ehmep_orphaned) ? '1' : '0';
    echo '<span class="ehmep-orphan-flag" data-ehmep-orphan="' . esc_attr($orphan) . '"></span>';
  }
}

new Eifelhoster_Menu_Editor_Pro();
