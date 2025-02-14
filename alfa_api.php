<?php

/**
 * Plugin Name: Alfa Business API
 * Description: Endpoints API y funcionalidad de seguimiento (UUID y registro de visitas) para WordPress.
 * Version: 1.0
 * Author: Mateo Llerena
 */

if (! defined('ABSPATH')) {
  exit;
}

/* ============================================================================
   DEFINICIONES DUMMY (REEMPLAZAR CON TUS FUNCIONES SI LAS TIENES)
============================================================================ */
if (! function_exists('get_custom_all_products')) {
  function get_custom_all_products()
  {
    return array();
  }
}
if (! function_exists('get_products_for_catalog')) {
  function get_products_for_catalog($post_id)
  {
    return array();
  }
}
if (! function_exists('get_categories_for_catalog')) {
  function get_categories_for_catalog($post_id)
  {
    return array();
  }
}
if (! function_exists('parse_form_fields')) {
  function parse_form_fields($content)
  {
    return array();
  }
}

/* ============================================================================
   PROTECCIÓN DE RUTAS (SECURITY)
============================================================================ */
function alfa_business_permission_callback(WP_REST_Request $request)
{
  $token      = $request->get_param('token');
  $uid_vendor = $request->get_param('uid_vendor');

  if (empty($token) || empty($uid_vendor)) {
    return new WP_Error('forbidden', 'Debe proporcionar los parámetros token y uid_vendor.', array('status' => 401));
  }

  global $wpdb;
  $table_name = $wpdb->prefix . 'alfa_business_config';
  $registro   = $wpdb->get_row("SELECT * FROM $table_name LIMIT 1");

  if (! $registro) {
    return new WP_Error('forbidden', 'No se ha configurado token y uid_vendor en el sistema.', array('status' => 401));
  }

  if ($token !== $registro->token || $uid_vendor !== $registro->uid_vendor) {
    return new WP_Error('forbidden', 'Token o uid_vendor incorrectos.', array('status' => 401));
  }

  return true;
}

/* ============================================================================
   SECTION 1: ENDPOINTS REST API
============================================================================ */
// Endpoint: /custom/v1/saludo
add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/saludo', array(
    'methods'             => 'GET',
    'callback'            => 'entregar_hola_mundo',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});
function entregar_hola_mundo()
{
  return new WP_REST_Response(array('message' => 'Hola Mateo'), 200);
}

// Endpoint: /custom/v1/general
add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/general', array(
    'methods'             => 'GET',
    'callback'            => 'get_custom_pages_and_posts',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});
function get_custom_pages_and_posts()
{
  $args  = array(
    'post_type'   => array('page', 'post', 'catalogo'),
    'post_status' => 'publish',
    'numberposts' => -1
  );
  $items = get_posts($args);
  $data  = array();

  foreach ($items as $item) {
    $content         = get_post_field('post_content', $item->ID);
    $content         = strip_shortcodes($content);
    $content         = preg_replace('/\[[^\]]*\]/', '', $content);
    $content         = wp_strip_all_tags($content);
    $cleaned_content = trim(preg_replace('/\s+/', ' ', $content));
    $title           = get_the_title($item->ID);
    $url             = get_permalink($item->ID);
    $type            = ($item->post_type === 'page') ? 'Página' : (($item->post_type === 'post') ? 'Entrada' : (($item->post_type === 'catalogo') ? 'Catálogo' : ''));
    $response_item   = array(
      'Tipo'          => $type,
      'Nombre Página' => $title,
      'URL'           => $url,
      'Contenido'     => $cleaned_content
    );
    if ($type === 'Catálogo') {
      $products   = get_products_for_catalog($item->ID);
      $categories = get_categories_for_catalog($item->ID);
      $response_item['Productos']  = ! empty($products) ? $products : array();
      $response_item['Categorias'] = ! empty($categories) ? $categories : array();
    }
    if ($type === 'Entrada') {
      $thumbnail_id = get_post_thumbnail_id($item->ID);
      if ($thumbnail_id) {
        $image_src = wp_get_attachment_image_src($thumbnail_id, 'full');
        $image_url = $image_src ? $image_src[0] : '';
        if (! empty($image_url)) {
          $attachment_post        = get_post($thumbnail_id);
          $attachment_caption     = $attachment_post ? $attachment_post->post_excerpt : '';
          $attachment_description = $attachment_post ? $attachment_post->post_content : '';
          $attachment_alt         = get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true);
          $response_item['Imagen'] = array(
            'url'               => $image_url,
            'leyenda'           => $attachment_caption ?: '',
            'Descripción'       => $attachment_description ?: '',
            'texto_alternativo' => $attachment_alt ?: ''
          );
        }
      }
    }
    $form_fields = parse_form_fields($content);
    if (! empty($form_fields)) {
      $response_item['Formulario'] = array(
        'tipo'        => 'Formulario de Evento',
        'descripcion' => 'Formulario para crear un evento de intención',
        'campos'      => $form_fields
      );
    }
    $data[] = $response_item;
  }
  $productos               = get_custom_all_products();
  global $master_categories;
  if (! isset($master_categories) || empty($master_categories)) {
    $master_categories = array();
  }
  $master_categories_formatted = array_values($master_categories);
  $response = array(
    'Datos'            => $data,
    'Productos'        => $productos,
    'MasterCategories' => $master_categories_formatted
  );
  return new WP_REST_Response($response, 200);
}

// Endpoint: /custom/v1/web
add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/web', array(
    'methods'             => 'GET',
    'callback'            => 'get_web_data',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});
function get_web_data()
{
  $args  = array(
    'post_type'   => array('page', 'post'),
    'post_status' => 'publish',
    'numberposts' => -1
  );
  $items = get_posts($args);
  $data  = array();
  foreach ($items as $item) {
    $content         = get_post_field('post_content', $item->ID);
    $content         = strip_shortcodes($content);
    $content         = preg_replace('/\[[^\]]*\]/', '', $content);
    $content         = wp_strip_all_tags($content);
    $cleaned_content = trim(preg_replace('/\s+/', ' ', $content));
    $type            = ($item->post_type === 'page') ? 'Página' : 'Entrada';
    $data[]          = array(
      'Tipo'      => $type,
      'Nombre'    => get_the_title($item->ID),
      'URL'       => get_permalink($item->ID),
      'Contenido' => $cleaned_content
    );
  }
  return new WP_REST_Response(array('Datos' => $data), 200);
}


add_action('rest_api_init', 'alfa_business_register_locations_endpoint');
function alfa_business_register_locations_endpoint()
{
  register_rest_route('custom/v1', '/sucursales', array(
    'methods'             => 'GET',
    'callback'            => 'alfa_business_get_locations',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
}
function alfa_business_get_locations(WP_REST_Request $request)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'alfa_business_locations';

  // Obtenemos el valor actual del cache buster (o 0 si no existe)
  $cache_buster = get_option('alfa_business_cache_buster', 0);

  // Agregamos una condición dummy en la consulta usando el cache buster.
  $results = $wpdb->get_results(
    "SELECT SQL_NO_CACHE * FROM {$table_name} WHERE 1=1 AND '$cache_buster' = '$cache_buster'",
    ARRAY_A
  );

  $response = new WP_REST_Response($results);
  $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
  $response->header('Pragma', 'no-cache');
  $response->header('Expires', '0');

  return $response;
}





/* ============================================================================
   SECTION 2: FUNCIONALIDAD DE SEGUIMIENTO (TRACKING)
============================================================================ */
if (! function_exists('su_obtener_ip')) {
  function su_obtener_ip()
  {
    return ! empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'sin_ip';
  }
}
if (! function_exists('su_determinar_tipo_dispositivo')) {
  function su_determinar_tipo_dispositivo($user_agent)
  {
    if (strpos($user_agent, 'Mobile') !== false) {
      return 'mobile';
    }
    return 'desktop';
  }
}
if (! function_exists('su_obtener_ubicacion')) {
  function su_obtener_ubicacion($ip_address)
  {
    return 'desconocido';
  }
}

register_activation_hook(__FILE__, 'su_crear_tabla_seguimiento');
function su_crear_tabla_seguimiento()
{
  global $wpdb;
  $tabla = $wpdb->prefix . 'seguimiento_usuario';
  $charset_collate = $wpdb->get_charset_collate();
  if ($wpdb->get_var("SHOW TABLES LIKE '{$tabla}'") != $tabla) {
    $sql = "CREATE TABLE {$tabla} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            uid VARCHAR(255) NOT NULL,
            url TEXT NOT NULL,
            parametros TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            referer TEXT DEFAULT NULL,
            device_type VARCHAR(50) DEFAULT NULL,
            location VARCHAR(255) DEFAULT NULL,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX(uid)
        ) {$charset_collate};";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    error_log("Tabla {$tabla} creada para seguimiento de usuarios.");
  } else {
    error_log("Tabla {$tabla} ya existe.");
  }
}

function su_capturar_us_id()
{
  error_log("Ejecutando su_capturar_us_id(), GET: " . print_r($_GET, true));
  if (isset($_GET['uid']) && ! empty($_GET['uid'])) {
    $uid = sanitize_text_field($_GET['uid']);
    $current_url = home_url($_SERVER['REQUEST_URI']);
    $parametros = $_GET;
    error_log("Capturado uid: {$uid} en URL: {$current_url}");
    su_guardar_visita($uid, $current_url, $parametros);
    setcookie('su_uid', $uid, time() + (10 * YEAR_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
    $_COOKIE['su_uid'] = $uid;
  }
}
add_action('init', 'su_capturar_us_id', 1);

function su_registrar_actividad()
{
  if (isset($_COOKIE['su_uid']) && ! empty($_COOKIE['su_uid'])) {
    $uid = sanitize_text_field($_COOKIE['su_uid']);
    $current_url = home_url($_SERVER['REQUEST_URI']);
    $parametros = $_GET;
    error_log("Registrando actividad para uid: {$uid} en URL: {$current_url}");
    global $wpdb;
    $tabla = $wpdb->prefix . 'seguimiento_usuario';
    $ultima_visita = $wpdb->get_row($wpdb->prepare("SELECT url FROM {$tabla} WHERE uid = %s ORDER BY fecha DESC LIMIT 1", $uid));
    if (! $ultima_visita || $ultima_visita->url !== $current_url) {
      su_guardar_visita($uid, $current_url, $parametros);
    } else {
      error_log("URL ya registrada previamente para uid: {$uid}");
    }
  } else {
    error_log("su_registrar_actividad: No se encontró la cookie su_uid.");
  }
}
add_action('wp', 'su_registrar_actividad', 1);

function su_guardar_visita($uid, $url, $parametros)
{
  global $wpdb;
  $tabla = $wpdb->prefix . 'seguimiento_usuario';
  $ip_address = su_obtener_ip();
  $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
  $referer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';
  $device_type = su_determinar_tipo_dispositivo($user_agent);
  $location = su_obtener_ubicacion($ip_address);
  $parametros_json = ! empty($parametros) ? wp_json_encode($parametros) : null;
  error_log("Ejecutando su_guardar_visita() para uid: {$uid} en URL: {$url}");
  $resultado = $wpdb->insert($tabla, array(
    'uid'         => $uid,
    'url'         => $url,
    'parametros'  => $parametros_json,
    'ip_address'  => $ip_address,
    'user_agent'  => $user_agent,
    'referer'     => $referer,
    'device_type' => $device_type,
    'location'    => $location,
    'fecha'       => current_time('mysql')
  ), array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'));
  if ($resultado === false) {
    error_log("Error al insertar visita para uid: {$uid}. Error: " . $wpdb->last_error);
  } else {
    error_log("Visita registrada para uid: {$uid} en URL: {$url}");
  }
}

function su_registrar_endpoint_api()
{
  register_rest_route('custom/v1', '/user', array(
    'methods'             => 'GET',
    'callback'            => 'su_obtener_historial',
    'args'                => array(
      'uid' => array(
        'required'          => true,
        'sanitize_callback' => 'sanitize_text_field',
        'validate_callback' => function ($param, $request, $key) {
          return is_string($param) && ! empty($param);
        }
      )
    ),
    'permission_callback' => 'alfa_business_permission_callback'
  ));
  error_log("Endpoint REST /custom/v1/user registrado.");
}
add_action('rest_api_init', 'su_registrar_endpoint_api');
function su_obtener_historial(WP_REST_Request $request)
{
  $uid = $request->get_param('uid');
  global $wpdb;
  $tabla = $wpdb->prefix . 'seguimiento_usuario';
  $resultados = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tabla} WHERE uid = %s ORDER BY fecha DESC", $uid), ARRAY_A);
  if (empty($resultados)) {
    error_log("No se encontró historial para uid: {$uid}");
    return new WP_Error('no_data', 'No se encontró historial para este uid.', array('status' => 404));
  }
  error_log("Historial obtenido para uid: {$uid}");
  return rest_ensure_response($resultados);
}

/* ============================================================================
   SECTION 3: ADMIN PANEL - CONFIGURACIÓN ALFA BUSINESS API (SECURITY)
============================================================================ */
function alfa_business_create_config_table()
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'alfa_business_config';
  $charset_collate = $wpdb->get_charset_collate();
  $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        token VARCHAR(255) NOT NULL,
        uid_vendor VARCHAR(255) NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
}
register_activation_hook(__FILE__, 'alfa_business_create_config_table');
function alfa_business_admin_menu()
{
  add_menu_page(
    'Alfa Business API',
    'Alfa Business API',
    'manage_options',
    'alfa-business-p',
    'alfa_business_settings_page',
    'dashicons-admin-network',
    81
  );
}
add_action('admin_menu', 'alfa_business_admin_menu');
function alfa_business_settings_page()
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'alfa_business_config';

  if (isset($_POST['alfa_business_clear_cache']) && check_admin_referer('alfa_business_clear_cache_action', 'alfa_business_clear_cache_nonce')) {
    wp_cache_flush();
    // Actualizamos la opción "alfa_business_cache_buster" con la marca de tiempo actual.
    update_option('alfa_business_cache_buster', time());
    echo '<div class="updated"><p>Caché vaciada correctamente.</p></div>';
  }



  if (isset($_POST['alfa_business_submit'])) {
    if (! isset($_POST['alfa_business_nonce']) || ! wp_verify_nonce($_POST['alfa_business_nonce'], 'alfa_business_save')) {
      echo '<div class="error"><p>Error de seguridad. Inténtalo de nuevo.</p></div>';
    } else {
      $token = sanitize_text_field($_POST['alfa_business_token']);
      $uid_vendor = sanitize_text_field($_POST['alfa_business_uid_vendor']);
      $registro = $wpdb->get_row("SELECT * FROM $table_name LIMIT 1");
      if ($registro) {
        $resultado = $wpdb->update(
          $table_name,
          array('token' => $token, 'uid_vendor' => $uid_vendor),
          array('id' => $registro->id),
          array('%s', '%s'),
          array('%d')
        );
        if ($resultado !== false) {
          echo '<div class="updated"><p>Registro actualizado correctamente.</p></div>';
        } else {
          echo '<div class="error"><p>Error al actualizar el registro.</p></div>';
        }
      } else {
        $resultado = $wpdb->insert(
          $table_name,
          array('token' => $token, 'uid_vendor' => $uid_vendor),
          array('%s', '%s')
        );
        if ($resultado) {
          echo '<div class="updated"><p>Registro guardado correctamente.</p></div>';
        } else {
          echo '<div class="error"><p>Error al guardar el registro.</p></div>';
        }
      }
    }
  }
  if (isset($_POST['alfa_business_clear'])) {
    if (! isset($_POST['alfa_business_nonce']) || ! wp_verify_nonce($_POST['alfa_business_nonce'], 'alfa_business_save')) {
      echo '<div class="error"><p>Error de seguridad. Inténtalo de nuevo.</p></div>';
    } else {
      $resultado = $wpdb->query("DELETE FROM $table_name");
      if ($resultado !== false) {
        echo '<div class="updated"><p>Registro eliminado correctamente.</p></div>';
      } else {
        echo '<div class="error"><p>Error al eliminar el registro.</p></div>';
      }
    }
  }
  if (isset($_POST['clear_tracking_table'])) {
    if (! isset($_POST['alfa_business_nonce']) || ! wp_verify_nonce($_POST['alfa_business_nonce'], 'alfa_business_save')) {
      echo '<div class="error"><p>Error de seguridad. Inténtalo de nuevo.</p></div>';
    } else {
      $tracking_table = $wpdb->prefix . 'seguimiento_usuario';
      $resultado = $wpdb->query("DROP TABLE IF EXISTS $tracking_table");
      if ($resultado !== false) {
        su_crear_tabla_seguimiento();
        echo '<div class="updated"><p>La tabla de seguimiento ha sido eliminada y recreada correctamente.</p></div>';
      } else {
        echo '<div class="error"><p>Error al borrar la tabla de seguimiento.</p></div>';
      }
    }
  }
  $registro = $wpdb->get_row("SELECT * FROM $table_name LIMIT 1");
  $current_token = $registro ? $registro->token : '';
  $current_uid_vendor = $registro ? $registro->uid_vendor : '';
?>
  <div class="wrap">
    <h1>Configuración Alfa Business API</h1>
    <form method="post" action="">
      <?php wp_nonce_field('alfa_business_save', 'alfa_business_nonce'); ?>
      <table class="form-table">
        <tr>
          <th scope="row"><label for="alfa_business_token">Token</label></th>
          <td><input type="text" name="alfa_business_token" id="alfa_business_token" value="<?php echo esc_attr($current_token); ?>" class="regular-text" /></td>
        </tr>
        <tr>
          <th scope="row"><label for="alfa_business_uid_vendor">UID Vendor</label></th>
          <td><input type="text" name="alfa_business_uid_vendor" id="alfa_business_uid_vendor" value="<?php echo esc_attr($current_uid_vendor); ?>" class="regular-text" /></td>
        </tr>
      </table>
      <div style="display: flex; gap: 10px;">
        <?php echo submit_button('Guardar', 'primary', 'alfa_business_submit', false); ?>
        <?php echo submit_button('Limpiar Configuración', 'secondary', 'alfa_business_clear', false); ?>
      </div>
    </form>
    <!-- Botón para limpiar la caché -->
    <form method="post" action="" style="margin-top:20px;">
      <?php wp_nonce_field('alfa_business_clear_cache_action', 'alfa_business_clear_cache_nonce'); ?>
      <?php echo submit_button('Limpiar Caché', 'secondary', 'alfa_business_clear_cache', false); ?>
    </form>

    <?php
    // Mostrar sección de Ubicaciones
    alfa_business_locations_settings_section();
    ?>
  </div>
<?php
}

/* ============================================================================
   SECTION 4: CONFIGURACIÓN DE UBICACIONES
============================================================================ */
function alfa_business_create_locations_table()
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'alfa_business_locations';
  $charset_collate = $wpdb->get_charset_collate();
  $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        location_id VARCHAR(100) NOT NULL,
        url_ubicacion TEXT NOT NULL,
        user_id VARCHAR(50) NOT NULL,
        nombre VARCHAR(255) NOT NULL,
        direccion TEXT NOT NULL,
        descripcion TEXT,
        horarios TEXT,
        lat DECIMAL(10, 6) NOT NULL,
        lng DECIMAL(10, 6) NOT NULL,
        telefono VARCHAR(50),
        PRIMARY KEY (id)
    ) $charset_collate;";
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
  error_log("Tabla $table_name creada/actualizada para ubicaciones.");
}
register_activation_hook(__FILE__, 'alfa_business_create_locations_table');

function alfa_business_locations_actions_handler()
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'alfa_business_locations';
  if (isset($_POST['alfa_business_locations_nonce']) && ! wp_verify_nonce($_POST['alfa_business_locations_nonce'], 'alfa_business_save_locations')) {
    echo '<div class="error"><p>Error de seguridad en Ubicaciones. Inténtalo de nuevo.</p></div>';
    return;
  }
  if (isset($_POST['delete_location']) && ! empty($_POST['location_record_id'])) {
    $record_id = intval($_POST['location_record_id']);
    $result = $wpdb->delete($table_name, array('id' => $record_id), array('%d'));
    if ($result !== false) {
      echo '<div class="updated"><p>Ubicación eliminada correctamente.</p></div>';
    } else {
      echo '<div class="error"><p>Error al eliminar la ubicación.</p></div>';
    }
  }
  if (isset($_POST['update_location']) && ! empty($_POST['location_record_id'])) {
    $record_id = intval($_POST['location_record_id']);
    $data = array(
      'location_id'   => sanitize_text_field($_POST['location_id']),
      'url_ubicacion' => esc_url_raw($_POST['url_ubicacion']),
      'user_id'       => sanitize_text_field($_POST['user_id']),
      'nombre'        => sanitize_text_field($_POST['nombre']),
      'direccion'     => sanitize_text_field($_POST['direccion']),
      'descripcion'   => sanitize_text_field($_POST['descripcion']),
      'horarios'      => sanitize_text_field($_POST['horarios']),
      'lat'           => floatval($_POST['lat']),
      'lng'           => floatval($_POST['lng']),
      'telefono'      => sanitize_text_field($_POST['telefono'])
    );
    $format = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s');
    $result = $wpdb->update($table_name, $data, array('id' => $record_id), $format, array('%d'));
    if ($result !== false) {
      wp_cache_flush();
      echo '<div class="updated"><p>Ubicación actualizada correctamente.</p></div>';
    } else {
      echo '<div class="error"><p>Error al actualizar la ubicación.</p></div>';
    }
  }
  if (isset($_POST['alfa_business_add_location'])) {
    $data = array(
      'location_id'   => sanitize_text_field($_POST['location_id']),
      'url_ubicacion' => esc_url_raw($_POST['url_ubicacion']),
      'user_id'       => sanitize_text_field($_POST['user_id']),
      'nombre'        => sanitize_text_field($_POST['nombre']),
      'direccion'     => sanitize_text_field($_POST['direccion']),
      'descripcion'   => sanitize_text_field($_POST['descripcion']),
      'horarios'      => sanitize_text_field($_POST['horarios']),
      'lat'           => floatval($_POST['lat']),
      'lng'           => floatval($_POST['lng']),
      'telefono'      => sanitize_text_field($_POST['telefono'])
    );
    $format = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s');
    $result = $wpdb->insert($table_name, $data, $format);
    if ($result !== false) {
      echo '<div class="updated"><p>Ubicación agregada correctamente.</p></div>';
    } else {
      echo '<div class="error"><p>Error al agregar la ubicación.</p></div>';
    }
  }
  if (isset($_POST['reset_locations_table'])) {
    $result = $wpdb->query("TRUNCATE TABLE $table_name");
    if ($result !== false) {
      wp_cache_flush();
      echo '<div class="updated"><p>La tabla de ubicaciones ha sido vaciada correctamente.</p></div>';
    } else {
      echo '<div class="error"><p>Error al vaciar la tabla de ubicaciones.</p></div>';
    }
  }
}
add_action('admin_init', 'alfa_business_locations_actions_handler');

function alfa_business_locations_settings_section()
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'alfa_business_locations';
  $editing = false;
  $edit_record = null;
  if (isset($_GET['action']) && $_GET['action'] == 'edit_location' && isset($_GET['record_id'])) {
    $record_id = intval($_GET['record_id']);
    $edit_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $record_id), ARRAY_A);
    if ($edit_record) {
      $editing = true;
    }
  }
?>
  <div class="wrap">
    <hr>
    <h2><?php echo $editing ? "Editar Ubicación" : "Agregar Ubicación"; ?></h2>
    <form method="post" action="">
      <?php wp_nonce_field('alfa_business_save_locations', 'alfa_business_locations_nonce'); ?>
      <?php if ($editing) : ?>
        <input type="hidden" name="location_record_id" value="<?php echo esc_attr($edit_record['id']); ?>">
      <?php endif; ?>
      <table class="form-table">
        <tr>
          <th scope="row"><label for="location_id">ID</label></th>
          <td><input type="text" name="location_id" id="location_id" placeholder="ej: caracol" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['location_id']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="url_ubicacion">URL Ubicación</label></th>
          <td><input type="text" name="url_ubicacion" id="url_ubicacion" placeholder="https://www.nyc.com.ec/ubicaciones?location=caracol" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['url_ubicacion']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="user_id">User ID</label></th>
          <td><input type="text" name="user_id" id="user_id" placeholder="ej: 39" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['user_id']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="nombre">Nombre</label></th>
          <td><input type="text" name="nombre" id="nombre" placeholder="Nombre de la ubicación" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['nombre']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="direccion">Dirección</label></th>
          <td><input type="text" name="direccion" id="direccion" placeholder="Dirección completa" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['direccion']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="descripcion">Descripción</label></th>
          <td><input type="text" name="descripcion" id="descripcion" placeholder="Descripción" class="regular-text" value="<?php echo $editing ? esc_attr($edit_record['descripcion']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="horarios">Horarios</label></th>
          <td><input type="text" name="horarios" id="horarios" placeholder="Ej: 10h00 a 20h00" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['horarios']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="lat">Latitud</label></th>
          <td><input type="number" step="0.000001" name="lat" id="lat" placeholder="-0.176281" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['lat']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="lng">Longitud</label></th>
          <td><input type="number" step="0.000001" name="lng" id="lng" placeholder="-78.485821" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['lng']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="telefono">Teléfono</label></th>
          <td><input type="text" name="telefono" id="telefono" placeholder="+593984190433" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['telefono']) : ''; ?>"></td>
        </tr>
      </table>
      <?php if ($editing) : ?>
        <?php submit_button('Actualizar Ubicación', 'primary', 'update_location'); ?>
        <a href="<?php echo admin_url('admin.php?page=alfa-business-p'); ?>" class="button">Cancelar Edición</a>
      <?php else : ?>
        <?php submit_button('Agregar Ubicación', 'primary', 'alfa_business_add_location'); ?>
      <?php endif; ?>
    </form>
    <hr>
    <h2>Listado de Ubicaciones</h2>
    <form method="post" action="">
      <?php wp_nonce_field('alfa_business_save_locations', 'alfa_business_locations_nonce'); ?>
      <?php submit_button('Resetear Tabla de Ubicaciones', 'secondary', 'reset_locations_table'); ?>
    </form>
    <?php
    $locations = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);
    if ($locations) {
    ?>
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Location ID</th>
            <th>URL Ubicación</th>
            <th>User ID</th>
            <th>Nombre</th>
            <th>Dirección</th>
            <th>Descripción</th>
            <th>Horarios</th>
            <th>Lat</th>
            <th>Lng</th>
            <th>Teléfono</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($locations as $loc) : ?>
            <tr>
              <td><?php echo esc_html($loc['id']); ?></td>
              <td><?php echo esc_html($loc['location_id']); ?></td>
              <td><?php echo esc_html($loc['url_ubicacion']); ?></td>
              <td><?php echo esc_html($loc['user_id']); ?></td>
              <td><?php echo esc_html($loc['nombre']); ?></td>
              <td><?php echo esc_html($loc['direccion']); ?></td>
              <td><?php echo esc_html($loc['descripcion']); ?></td>
              <td><?php echo esc_html($loc['horarios']); ?></td>
              <td><?php echo esc_html($loc['lat']); ?></td>
              <td><?php echo esc_html($loc['lng']); ?></td>
              <td><?php echo esc_html($loc['telefono']); ?></td>
              <td>
                <form method="post" style="display:inline;">
                  <?php wp_nonce_field('alfa_business_save_locations', 'alfa_business_locations_nonce'); ?>
                  <input type="hidden" name="location_record_id" value="<?php echo esc_attr($loc['id']); ?>">
                  <input type="submit" name="delete_location" class="button" value="Eliminar" onclick="return confirm('¿Seguro que deseas eliminar esta ubicación?');">
                </form>
                <a href="<?php echo admin_url('admin.php?page=alfa-business-p&action=edit_location&record_id=' . intval($loc['id'])); ?>" class="button">Editar</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
  <?php
    } else {
      echo '<p>No se han registrado ubicaciones.</p>';
    }
    echo '</div>';
  }
  ?>