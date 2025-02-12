<?php

/**
 * Plugin Name: Alfa Business API
 * Description: Endpoints API y funcionalidad de seguimiento (UUID y registro de visitas) para WordPress.
 * Version: 1.0
 * Author: Mateo Llerena
 */

// Evitar acceso directo
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
   Usamos "uid_vendor" para la seguridad.
============================================================================ */
function alfa_business_permission_callback(WP_REST_Request $request)
{
  $token      = $request->get_param('token');
  $uid_vendor = $request->get_param('uid_vendor');

  if (empty($token) || empty($uid_vendor)) {
    return new WP_Error(
      'forbidden',
      'Debe proporcionar los parámetros token y uid_vendor.',
      array('status' => 401)
    );
  }

  global $wpdb;
  $table_name = $wpdb->prefix . 'alfa_business_config';
  $registro   = $wpdb->get_row("SELECT * FROM $table_name LIMIT 1");

  if (! $registro) {
    return new WP_Error(
      'forbidden',
      'No se ha configurado token y uid_vendor en el sistema.',
      array('status' => 401)
    );
  }

  if ($token !== $registro->token || $uid_vendor !== $registro->uid_vendor) {
    return new WP_Error(
      'forbidden',
      'Token o uid_vendor incorrectos.',
      array('status' => 401)
    );
  }

  return true;
}

/* ============================================================================
   SECTION 1: ENDPOINTS REST API
============================================================================ */
// Endpoint: custom/v1/saludo
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

// Endpoint: custom/v1/general
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
    $title = get_the_title($item->ID);
    $url   = get_permalink($item->ID);
    $type  = ($item->post_type === 'page') ? 'Página' : (($item->post_type === 'post') ? 'Entrada' : (($item->post_type === 'catalogo') ? 'Catálogo' : ''));
    $response_item = array(
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

  $productos = get_custom_all_products();
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

// Endpoint: custom/v1/web
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
    $type = ($item->post_type === 'page') ? 'Página' : 'Entrada';
    $data[] = array(
      'Tipo'      => $type,
      'Nombre'    => get_the_title($item->ID),
      'URL'       => get_permalink($item->ID),
      'Contenido' => $cleaned_content
    );
  }
  return new WP_REST_Response(array('Datos' => $data), 200);
}

// Endpoint: custom/v1/commerce
add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/commerce', array(
    'methods'             => 'GET',
    'callback'            => 'get_commerce_data',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});
function get_commerce_data()
{
  $productos = get_custom_all_products();
  global $master_categories;
  if (! isset($master_categories) || empty($master_categories)) {
    $master_categories = array();
  }
  $master_categories_formatted = array_values($master_categories);
  $response = array(
    'Productos'        => $productos,
    'MasterCategories' => $master_categories_formatted
  );
  return new WP_REST_Response($response, 200);
}

// Endpoint: custom/v1/commerce/products
add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/commerce/products', array(
    'methods'             => 'GET',
    'callback'            => 'get_commerce_products_filtered',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});
if (! function_exists('get_commerce_products_filtered')) {
  function get_commerce_products_filtered(WP_REST_Request $request)
  {
    $keywords_param = $request->get_param('keywords');
    $stock_param    = $request->get_param('stock');
    $limit_param    = $request->get_param('limit');
    $keywords = array();
    if ($keywords_param) {
      $decoded = json_decode($keywords_param, true);
      if (is_array($decoded)) {
        $keywords = $decoded;
      } else {
        $keywords = array($keywords_param);
      }
    }
    $stock_filter = null;
    if ($stock_param !== null && $stock_param !== '') {
      $stock_filter = intval($stock_param);
    }
    $limit = null;
    if ($limit_param !== null && $limit_param !== '') {
      $limit = intval($limit_param);
    }
    if (function_exists('get_custom_all_products')) {
      $products = get_custom_all_products();
    } else {
      $args   = array(
        'post_type'      => 'product',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
      );
      $query  = new WP_Query($args);
      $products = array();
      if ($query->have_posts()) {
        while ($query->have_posts()) {
          $query->the_post();
          $products[] = array(
            'ID'          => get_the_ID(),
            'Nombre'      => get_the_title(),
            'Stock'       => get_post_meta(get_the_ID(), '_stock', true),
            'Descripción' => get_the_content(),
          );
        }
        wp_reset_postdata();
      }
    }
    if ($stock_filter !== null) {
      if ($stock_filter === 1) {
        $products = array_filter($products, function ($product) {
          return intval($product['Stock']) > 0;
        });
      } else {
        $products = array_filter($products, function ($product) {
          return intval($product['Stock']) <= 0;
        });
      }
    }
    if (! empty($keywords)) {
      foreach ($products as &$product) {
        $score = 0;
        $haystack = strtolower($product['Nombre'] . ' ' . $product['Descripción']);
        foreach ($keywords as $keyword) {
          $keyword = strtolower($keyword);
          $score += substr_count($haystack, $keyword);
        }
        $product['match_score'] = $score;
      }
      unset($product);
      $products = array_filter($products, function ($product) {
        return $product['match_score'] > 0;
      });
      usort($products, function ($a, $b) {
        return $b['match_score'] - $a['match_score'];
      });
    }
    if ($limit !== null && $limit > 0) {
      $products = array_slice($products, 0, $limit);
    }
    foreach ($products as &$product) {
      if (isset($product['match_score'])) {
        unset($product['match_score']);
      }
    }
    unset($product);
    return new WP_REST_Response(array('Productos' => array_values($products)), 200);
  }
}

// Endpoint: custom/v1/commerce/categories
add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/commerce/categories', array(
    'methods'             => 'GET',
    'callback'            => 'get_commerce_categories',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});
if (! function_exists('get_commerce_categories')) {
  function get_commerce_categories()
  {
    $terms = get_terms(array(
      'taxonomy'   => 'product_cat',
      'hide_empty' => false,
    ));
    $categories = array();
    if (! is_wp_error($terms) && ! empty($terms)) {
      foreach ($terms as $term) {
        $categories[] = array(
          'id'          => $term->term_id,
          'name'        => $term->name,
          'slug'        => $term->slug,
          'description' => $term->description,
          'count'       => $term->count,
        );
      }
    }
    return new WP_REST_Response(array('Categories' => $categories), 200);
  }
}

// Endpoint: custom/v1/commerce/keywords-productos
add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/commerce/keywords-productos', array(
    'methods'             => 'GET',
    'callback'            => 'get_commerce_keywords',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});
function get_commerce_keywords()
{
  $args = array(
    'post_type'      => 'product',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
  );
  $query = new WP_Query($args);
  $all_keywords = array();
  if ($query->have_posts()) {
    while ($query->have_posts()) {
      $query->the_post();
      $post_id      = get_the_ID();
      $title        = get_the_title($post_id);
      $short_desc   = get_the_excerpt($post_id);
      $long_desc    = get_the_content(null, false, $post_id);
      $product_cats = wp_get_post_terms($post_id, 'product_cat', array('fields' => 'names'));
      $combined_text = $title . ' ' . $short_desc . ' ' . $long_desc . ' ' . implode(' ', $product_cats);
      $product_keywords = parse_text_to_keywords($combined_text);
      $all_keywords = array_merge($all_keywords, $product_keywords);
    }
  }
  wp_reset_postdata();
  $unique_keywords = array_unique($all_keywords);
  sort($unique_keywords);
  return new WP_REST_Response(array_values($unique_keywords), 200);
}
function parse_text_to_keywords($text)
{
  $text = mb_strtolower($text, 'UTF-8');
  $text = wp_strip_all_tags($text);
  $text = preg_replace('/[.,:;!\?\(\)\[\]\"]/', '', $text);
  $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
  $keywords = array_filter($words, function ($w) {
    return mb_strlen($w, 'UTF-8') >= 3;
  });
  return $keywords;
}

/* ============================================================================
   SECTION 2: FUNCIONALIDAD DE SEGUIMIENTO (TRACKING)
   Usaremos el parámetro y columna "us_id" para el seguimiento.
============================================================================ */

/**
 * (0) Funciones auxiliares para el tracking.
 * Puedes reemplazar estas funciones con tu lógica real si lo requieres.
 */
if (! function_exists('su_obtener_ip')) {
  function su_obtener_ip()
  {
    return !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : 'sin_ip';
  }
}
if (! function_exists('su_determinar_tipo_dispositivo')) {
  function su_determinar_tipo_dispositivo($user_agent)
  {
    // Detección simple: si el user agent contiene "Mobile", consideramos el dispositivo como móvil.
    if (strpos($user_agent, 'Mobile') !== false) {
      return 'mobile';
    }
    return 'desktop';
  }
}
if (! function_exists('su_obtener_ubicacion')) {
  function su_obtener_ubicacion($ip_address)
  {
    // Función dummy: en producción podrías integrar una API de geolocalización.
    return 'desconocido';
  }
}

// (1) Crea la tabla de seguimiento al activar el plugin (se utiliza el esquema simplificado)
register_activation_hook(__FILE__, 'su_crear_tabla_seguimiento');
function su_crear_tabla_seguimiento()
{
  global $wpdb;
  $tabla = $wpdb->prefix . 'seguimiento_usuario';
  $charset_collate = $wpdb->get_charset_collate();
  // Se incluyen las columnas adicionales: parámetros, device_type y location.
  if ($wpdb->get_var("SHOW TABLES LIKE '{$tabla}'") != $tabla) {
    $sql = "CREATE TABLE {$tabla} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            us_id VARCHAR(255) NOT NULL,
            url TEXT NOT NULL,
            parametros TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            referer TEXT DEFAULT NULL,
            device_type VARCHAR(50) DEFAULT NULL,
            location VARCHAR(255) DEFAULT NULL,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX(us_id)
        ) {$charset_collate};";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    error_log("Tabla {$tabla} creada para seguimiento de usuarios.");
  } else {
    error_log("Tabla {$tabla} ya existe.");
  }
}

// (2) Captura el parámetro us_id desde GET, establece la cookie y registra la visita.
function su_capturar_us_id()
{
  error_log("Ejecutando su_capturar_us_id(), GET: " . print_r($_GET, true));
  if (isset($_GET['us_id']) && !empty($_GET['us_id'])) {
    $us_id = sanitize_text_field($_GET['us_id']);
    $current_url = home_url($_SERVER['REQUEST_URI']);
    $parametros = $_GET;
    error_log("Capturado us_id: {$us_id} en URL: {$current_url}");
    su_guardar_visita($us_id, $current_url, $parametros);
    // Establece la cookie para futuros registros (10 años)
    setcookie('su_us_id', $us_id, time() + (10 * YEAR_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
    $_COOKIE['su_us_id'] = $us_id;
  }
}
add_action('init', 'su_capturar_us_id', 1);

// (3) Registra actividad en cada carga de página si existe la cookie.
function su_registrar_actividad()
{
  if (isset($_COOKIE['su_us_id']) && !empty($_COOKIE['su_us_id'])) {
    $us_id = sanitize_text_field($_COOKIE['su_us_id']);
    $current_url = home_url($_SERVER['REQUEST_URI']);
    $parametros = $_GET;
    error_log("Registrando actividad para us_id: {$us_id} en URL: {$current_url}");
    global $wpdb;
    $tabla = $wpdb->prefix . 'seguimiento_usuario';
    $ultima_visita = $wpdb->get_row(
      $wpdb->prepare("SELECT url FROM {$tabla} WHERE us_id = %s ORDER BY fecha DESC LIMIT 1", $us_id)
    );
    if (! $ultima_visita || $ultima_visita->url !== $current_url) {
      su_guardar_visita($us_id, $current_url, $parametros);
    } else {
      error_log("URL ya registrada previamente para us_id: {$us_id}");
    }
  } else {
    error_log("su_registrar_actividad: No se encontró la cookie su_us_id.");
  }
}
add_action('wp', 'su_registrar_actividad', 1);

// (4) Inserta un registro de visita en la base de datos.
function su_guardar_visita($us_id, $url, $parametros)
{
  global $wpdb;
  $tabla = $wpdb->prefix . 'seguimiento_usuario';
  $ip_address = su_obtener_ip();
  $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
  $referer    = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';
  $device_type = su_determinar_tipo_dispositivo($user_agent);
  $location = su_obtener_ubicacion($ip_address);
  $parametros_json = !empty($parametros) ? wp_json_encode($parametros) : null;
  error_log("Ejecutando su_guardar_visita() para us_id: {$us_id} en URL: {$url}");
  $resultado = $wpdb->insert(
    $tabla,
    array(
      'us_id'       => $us_id,
      'url'         => $url,
      'parametros'  => $parametros_json,
      'ip_address'  => $ip_address,
      'user_agent'  => $user_agent,
      'referer'     => $referer,
      'device_type' => $device_type,
      'location'    => $location,
      'fecha'       => current_time('mysql')
    ),
    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
  );
  if ($resultado === false) {
    error_log("Error al insertar visita para us_id: {$us_id}. Error: " . $wpdb->last_error);
  } else {
    error_log("Visita registrada para us_id: {$us_id} en URL: {$url}");
  }
}

/* ============================================================================
   SECTION 2: ENDPOINT /user - HISTORIAL DE USUARIO (TRACKING)
============================================================================ */
function su_registrar_endpoint_api()
{
  register_rest_route('custom/v1', '/user', array(
    'methods'             => 'GET',
    'callback'            => 'su_obtener_historial',
    'args'                => array(
      'us_id' => array(
        'required'          => true,
        'sanitize_callback' => 'sanitize_text_field',
        'validate_callback' => function ($param, $request, $key) {
          return is_string($param) && !empty($param);
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
  $us_id = $request->get_param('us_id');
  global $wpdb;
  $tabla = $wpdb->prefix . 'seguimiento_usuario';
  $resultados = $wpdb->get_results(
    $wpdb->prepare("SELECT * FROM {$tabla} WHERE us_id = %s ORDER BY fecha DESC", $us_id),
    ARRAY_A
  );
  if (empty($resultados)) {
    error_log("No se encontró historial para us_id: {$us_id}");
    return new WP_Error('no_data', 'No se encontró historial para este us_id.', array('status' => 404));
  }
  error_log("Historial obtenido para us_id: {$us_id}");
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

  // Botón modificado para eliminar (DROP) la tabla completa de seguimiento y recrearla
  if (isset($_POST['clear_tracking_table'])) {
    if (! isset($_POST['alfa_business_nonce']) || ! wp_verify_nonce($_POST['alfa_business_nonce'], 'alfa_business_save')) {
      echo '<div class="error"><p>Error de seguridad. Inténtalo de nuevo.</p></div>';
    } else {
      $tracking_table = $wpdb->prefix . 'seguimiento_usuario';
      $resultado = $wpdb->query("DROP TABLE IF EXISTS $tracking_table");
      if ($resultado !== false) {
        // Recrea la tabla inmediatamente
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
          <td>
            <input type="text" name="alfa_business_token" id="alfa_business_token" value="<?php echo esc_attr($current_token); ?>" class="regular-text" />
          </td>
        </tr>
        <tr>
          <th scope="row"><label for="alfa_business_uid_vendor">UID Vendor</label></th>
          <td>
            <input type="text" name="alfa_business_uid_vendor" id="alfa_business_uid_vendor" value="<?php echo esc_attr($current_uid_vendor); ?>" class="regular-text" />
          </td>
        </tr>
      </table>
      <div style="display: flex; gap: 10px;">
        <?php echo submit_button('Guardar', 'primary', 'alfa_business_submit', false); ?>
        <?php echo submit_button('Limpiar Configuración', 'secondary', 'alfa_business_clear', false); ?>
        <?php echo submit_button('Limpiar Tabla de Seguimiento', 'secondary', 'clear_tracking_table', false); ?>
      </div>
    </form>
  </div>
<?php
}
?>