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

/*  /COMMERCE/PRODUCTS */

add_action('rest_api_init', 'register_commerce_products_search_endpoint');

function register_commerce_products_search_endpoint()
{
  register_rest_route('custom/v1', '/commerce/products', array(
    'methods'             => 'GET',
    'callback'            => 'search_commerce_products_by_keywords_category_stock',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
}


function search_commerce_products_by_keywords_category_stock(WP_REST_Request $request)
{

  // === 1) Obtener parámetros del request
  $raw_keywords = $request->get_param('keywords');
  $raw_category = $request->get_param('category');
  $stock_param  = $request->get_param('stock');
  $limit_param  = $request->get_param('limit'); // Nuevo parámetro

  // === 2) Procesar 'category' => tax_query
  $tax_query = array();
  if (! empty($raw_category)) {
    $categories = json_decode($raw_category, true);
    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($categories)) {
      return new WP_Error(
        'bad_category_format',
        'El parámetro "category" debe ser un array JSON válido (ej: ["cat1","cat2"]).',
        array('status' => 400)
      );
    }
    $tax_query[] = array(
      'taxonomy' => 'product_cat',
      'field'    => 'slug',
      'terms'    => $categories,
      'operator' => 'IN',
    );
  }

  // === 3) Procesar 'stock' => meta_query de _stock_status
  // stock=1 => instock, stock=0 => outofstock
  $meta_query = array();
  if (isset($stock_param)) {
    if ($stock_param === '1') {
      $meta_query[] = array(
        'key'   => '_stock_status',
        'value' => 'instock',
      );
    } elseif ($stock_param === '0') {
      $meta_query[] = array(
        'key'   => '_stock_status',
        'value' => 'outofstock',
      );
    }
  }

  // === 4) Procesar keywords => queremos un comportamiento OR
  //     Si no hay 'keywords', simplemente consultamos todo.
  $accumulated_ids = array();
  $matches_count   = array(); // Array para contar cuántas keywords coinciden en cada product_id
  $hasKeywords     = false;

  if (! empty($raw_keywords)) {
    $keywords = json_decode($raw_keywords, true);
    if (json_last_error() !== JSON_ERROR_NONE || ! is_array($keywords)) {
      return new WP_Error(
        'bad_keywords_format',
        'El parámetro "keywords" no es un JSON de array válido. Ej: ["android","64"]',
        array('status' => 400)
      );
    }

    // Si hay keywords, activamos la lógica de OR.
    if (! empty($keywords)) {
      $hasKeywords = true;
      foreach ($keywords as $kw) {
        // Consulta parcial para cada palabra
        $args_kw = array(
          'post_type'      => 'product',
          'post_status'    => 'publish',
          'posts_per_page' => -1,
          'fields'         => 'ids',  // Solo necesitamos IDs
          's'              => $kw,    // Búsqueda textual
        );
        $query_kw = new WP_Query($args_kw);

        // Mezclar los IDs encontrados con los que ya teníamos
        $found_ids = $query_kw->posts; // Lista de IDs que hacen match con esta keyword
        $accumulated_ids = array_merge($accumulated_ids, $found_ids);

        // Incrementar conteo de coincidencias en $matches_count
        foreach ($found_ids as $pid) {
          if (! isset($matches_count[$pid])) {
            $matches_count[$pid] = 0;
          }
          $matches_count[$pid]++;
        }
      }

      // Quitar duplicados
      $accumulated_ids = array_unique($accumulated_ids);
    }
  }

  // === 5) Construir la "verdadera" query final
  //     Si hay keywords, filtramos 'post__in'
  //     Además usamos tax_query y meta_query
  $final_args = array(
    'post_type'      => 'product',
    'post_status'    => 'publish',
    'posts_per_page' => -1, // Obtenemos todo para poder ordenarlo después manualmente
  );

  // Si tuvimos keywords, filtrar solo los IDs que resultaron
  if ($hasKeywords) {
    // Si no hay IDs tras la búsqueda OR, podemos terminar vacíos
    if (empty($accumulated_ids)) {
      return new WP_REST_Response(array(), 200);
    }
    $final_args['post__in'] = $accumulated_ids;
  }

  if (! empty($tax_query)) {
    // Podríamos requerir 'relation' => 'AND' si tenemos varias taxonomías
    $final_args['tax_query'] = $tax_query;
  }

  if (! empty($meta_query)) {
    // Si tenemos más de una condición, igual podríamos ajustar 'relation'
    $final_args['meta_query'] = $meta_query;
  }

  // === 6) Ejecutar la consulta final
  $final_query = new WP_Query($final_args);

  if (! $final_query->have_posts()) {
    wp_reset_postdata();
    return new WP_REST_Response(array(), 200);
  }

  $results = array();
  while ($final_query->have_posts()) {
    $final_query->the_post();

    $product_id    = get_the_ID();
    $title         = get_the_title($product_id);
    $price         = get_post_meta($product_id, '_price', true);
    $regular_price = get_post_meta($product_id, '_regular_price', true);
    $sale_price    = get_post_meta($product_id, '_sale_price', true);
    $stock         = get_post_meta($product_id, '_stock', true);
    $stock_status  = get_post_meta($product_id, '_stock_status', true); // instock/outofstock
    $sku           = get_post_meta($product_id, '_sku', true);
    $thumbnail_url = get_the_post_thumbnail_url($product_id, 'full');
    $short_desc    = get_the_excerpt($product_id);
    $long_desc     = get_the_content(null, false, $product_id);

    // Obtener categorías
    $term_objs = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'all'));
    $categories_out = array();
    if (! empty($term_objs) && ! is_wp_error($term_objs)) {
      foreach ($term_objs as $cat) {
        $categories_out[] = array(
          'ID'          => $cat->term_id,
          'Nombre'      => $cat->name,
          'Slug'        => $cat->slug,
          'Descripción' => $cat->description,
        );
      }
    }

    // "Coincidencias": si $matches_count[$product_id] existe, lo usamos; si no, es 0.
    $coincidencias = isset($matches_count[$product_id]) ? $matches_count[$product_id] : 0;

    // Agregar al array de respuesta
    $results[] = array(
      'ID'                => $product_id,
      'Nombre'            => html_entity_decode($title),
      'SKU'               => $sku,
      'Stock'             => $stock,
      'Stock_Status'      => $stock_status,
      'Precio'            => $price,
      'Regular_Price'     => $regular_price,
      'Sale_Price'        => $sale_price,
      'URL'               => get_permalink($product_id) . "?uid={{_uid}}",
      'Imagen_Destacada'  => ! empty($thumbnail_url) ? $thumbnail_url : '',
      'Descripción'       => $long_desc,
      'Descripción_Corta' => $short_desc,
      'Categorias'        => $categories_out,
      'Coincidencias'     => intval($coincidencias),
    );
  }

  wp_reset_postdata();

  // === 7) Ordenar $results de mayor a menor según 'Coincidencias'
  usort($results, function ($a, $b) {
    return $b['Coincidencias'] - $a['Coincidencias'];
  });

  // === 8) Aplicar limit si existe
  $limit = absint($limit_param);
  if ($limit > 0) {
    $results = array_slice($results, 0, $limit);
  }

  // === 9) Retornar la respuesta
  return new WP_REST_Response($results, 200);
}





// ---------------------------------------------------------------------
// /V1/COMMERCE/KEYWORDS-PRODUCTOS
// ---------------------------------------------------------------------

add_action('rest_api_init', 'register_custom_commerce_keywords_endpoint');

function register_custom_commerce_keywords_endpoint()
{
  register_rest_route('custom/v1', '/commerce/keywords-productos', array(
    'methods'             => 'GET',
    'callback'            => 'get_commerce_keywords',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
}

/**
 * Callback que obtiene todas las palabras clave únicas de los productos.
 */
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
      $post_id         = get_the_ID();
      $title           = get_the_title($post_id);
      $short_desc      = get_the_excerpt($post_id);
      $long_desc       = get_the_content(null, false, $post_id);
      $product_cats    = wp_get_post_terms($post_id, 'product_cat', array('fields' => 'names'));

      // Combinar en un solo texto
      $combined_text = $title . ' ' . $short_desc . ' ' . $long_desc . ' ' . implode(' ', $product_cats);

      // Extraer palabras clave de este producto
      $product_keywords = parse_text_to_keywords($combined_text);

      // Mezclar con el array global
      $all_keywords = array_merge($all_keywords, $product_keywords);
    }
  }

  wp_reset_postdata();

  // Remover duplicados y ordenar
  $unique_keywords = array_unique($all_keywords);
  sort($unique_keywords);

  // Devolver la respuesta con las palabras únicas
  return new WP_REST_Response(array_values($unique_keywords), 200);
}

/**
 * Función auxiliar para extraer palabras clave de un texto.
 * Ajusta la lógica según tus necesidades (filtros, longitud mínima, etc.).
 */
function parse_text_to_keywords($text)
{
  // Convertir a minúsculas
  $text = mb_strtolower($text, 'UTF-8');

  // Remover HTML, saltos de línea, puntuaciones básicas
  $text = wp_strip_all_tags($text);
  $text = preg_replace('/[.,:;!\?\(\)\[\]\"]/', '', $text);

  // Separar por espacios
  $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

  // Filtrar palabras muy cortas (ejemplo: longitud >= 3)
  $keywords = array_filter($words, function ($w) {
    return mb_strlen($w, 'UTF-8') >= 3;
  });

  // Retornar array de palabras
  return $keywords;
}

// ---------------------------------------------------------------------
// /V1/COMMERCE
// ---------------------------------------------------------------------

/**
 * Registrar la ruta personalizada de la API para WooCommerce.
 */
add_action('rest_api_init', 'register_custom_commerce_endpoint');

function register_custom_commerce_endpoint()
{
  register_rest_route('custom/v1', '/commerce', array(
    'methods'             => 'GET',
    'callback'            => 'get_custom_commerce_data',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
}

/**
 * Callback que obtiene productos y categorías (WooCommerce).
 */
function get_custom_commerce_data()
{
  $products_data = array();

  // Obtener todos los productos publicados
  $args = array(
    'post_type'      => 'product',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
  );
  $query = new WP_Query($args);

  if ($query->have_posts()) {
    while ($query->have_posts()) {
      $query->the_post();

      $product_id    = get_the_ID();
      $price         = get_post_meta($product_id, '_price', true);
      $regular_price = get_post_meta($product_id, '_regular_price', true);
      $sale_price    = get_post_meta($product_id, '_sale_price', true);
      $stock         = get_post_meta($product_id, '_stock', true);
      $sku           = get_post_meta($product_id, '_sku', true);
      $thumbnail_url = get_the_post_thumbnail_url($product_id, 'full');
      $descripcion   = get_the_content(null, false, $product_id);
      $short_desc    = get_the_excerpt($product_id);

      // Obtener categorías (product_cat)
      $categorias_obj = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'all'));
      $categories     = array();
      if (!empty($categorias_obj) && !is_wp_error($categorias_obj)) {
        foreach ($categorias_obj as $cat) {
          $categories[] = array(
            'ID'          => $cat->term_id,
            'Nombre'      => $cat->name,
            'Slug'        => $cat->slug,
            'Descripción' => $cat->description,
          );
        }
      }

      // Agregar datos al array de productos
      $products_data[] = array(
        'ID'                => $product_id,
        'Nombre'            => html_entity_decode(get_the_title($product_id)),
        'SKU'               => $sku,
        'Stock'             => $stock,
        'Precio'            => $price,
        'Regular_Price'     => $regular_price,
        'Sale_Price'        => $sale_price,
        'URL'               => get_permalink($product_id),
        'Imagen_Destacada'  => !empty($thumbnail_url) ? $thumbnail_url : '',
        'Descripción'       => $descripcion,
        'Descripción_Corta' => $short_desc,
        'Categorias'        => $categories,
      );
    }
  }

  wp_reset_postdata();

  // Devolver la respuesta con los datos de todos los productos
  return new WP_REST_Response(array(
    'Productos' => $products_data
  ), 200);
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
    'numberposts' => -1,
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
      'Contenido'     => $cleaned_content,
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
            'texto_alternativo' => $attachment_alt ?: '',
          );
        }
      }
    }
    $form_fields = parse_form_fields($content);
    if (! empty($form_fields)) {
      $response_item['Formulario'] = array(
        'tipo'        => 'Formulario de Evento',
        'descripcion' => 'Formulario para crear un evento de intención',
        'campos'      => $form_fields,
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
    'MasterCategories' => $master_categories_formatted,
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
    'numberposts' => -1,
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
      'Contenido' => $cleaned_content,
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
    'permission_callback' => 'alfa_business_permission_callback',
  ));
}
function alfa_business_get_locations(WP_REST_Request $request)
{
  global $wpdb;
  $table_name  = $wpdb->prefix . 'alfa_business_locations';
  $cache_buster = get_option('alfa_business_cache_buster', 0);
  $results     = $wpdb->get_results("SELECT SQL_NO_CACHE * FROM {$table_name} WHERE 1=1 AND '$cache_buster' = '$cache_buster'", ARRAY_A);
  $response    = new WP_REST_Response($results);
  $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
  $response->header('Pragma', 'no-cache');
  $response->header('Expires', '0');
  return $response;
}


add_action('rest_api_init', 'alfa_business_register_rrss_endpoint');
function alfa_business_register_rrss_endpoint()
{
  register_rest_route('custom/v1', '/rrss', array(
    'methods'             => 'GET',
    'callback'            => 'alfa_business_get_rrss',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
}

function alfa_business_get_rrss(WP_REST_Request $request)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'alfa_business_rrss';
  $results    = $wpdb->get_results("SELECT SQL_NO_CACHE * FROM {$table_name}", ARRAY_A);

  $response = new WP_REST_Response($results);
  $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
  $response->header('Pragma', 'no-cache');
  $response->header('Expires', '0');

  return $response;
}




/* ============================================================================
   FUNCIONALIDAD DE SEGUIMIENTO (TRACKING)
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
    return (strpos($user_agent, 'Mobile') !== false) ? 'mobile' : 'desktop';
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
  }
}

function su_capturar_us_id()
{
  if (isset($_GET['uid']) && ! empty($_GET['uid'])) {
    $uid = sanitize_text_field($_GET['uid']);
    $current_url = home_url($_SERVER['REQUEST_URI']);
    $parametros = $_GET;

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

    global $wpdb;
    $tabla = $wpdb->prefix . 'seguimiento_usuario';
    $ultima_visita = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$tabla} WHERE uid = %s AND url = %s", $uid, $current_url));

    if (!$ultima_visita) {
      su_guardar_visita($uid, $current_url, $parametros);
    }
  }
}
add_action('wp', 'su_registrar_actividad', 1);

function su_guardar_visita($uid, $url, $parametros)
{
  global $wpdb;
  $tabla = $wpdb->prefix . 'seguimiento_usuario';
  $ip_address = su_obtener_ip();
  $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
  $referer = $_SERVER['HTTP_REFERER'] ?? '';
  $device_type = su_determinar_tipo_dispositivo($user_agent);
  $location = su_obtener_ubicacion($ip_address);
  $parametros_json = ! empty($parametros) ? wp_json_encode($parametros) : null;

  $wpdb->insert(
    $tabla,
    array(
      'uid' => $uid,
      'url' => $url,
      'parametros' => $parametros_json,
      'ip_address' => $ip_address,
      'user_agent' => $user_agent,
      'referer' => $referer,
      'device_type' => $device_type,
      'location' => $location,
      'fecha' => current_time('mysql')
    ),
    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
  );
}

function su_obtener_historial(WP_REST_Request $request)
{
  $uid = $request->get_param('uid');
  global $wpdb;
  $tabla = $wpdb->prefix . 'seguimiento_usuario';
  $resultados = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$tabla} WHERE uid = %s ORDER BY fecha DESC", $uid), ARRAY_A);

  return empty($resultados) ? new WP_Error('no_data', 'No se encontró historial para este uid.', array('status' => 404)) : rest_ensure_response($resultados);
}

add_action('rest_api_init', function () {
  register_rest_route('custom/v1', '/user', array(
    'methods' => 'GET',
    'callback' => 'su_obtener_historial',
    'args' => array(
      'uid' => array('required' => true, 'sanitize_callback' => 'sanitize_text_field')
    ),
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});


/* ============================================================================
   SECTION 3: ADMIN PANEL - CONFIGURACIÓN ALFA BUSINESS API (SECURITY)
============================================================================ */
function alfa_business_create_config_table()
{
  global $wpdb;
  $table_name      = $wpdb->prefix . 'alfa_business_config';
  $charset_collate = $wpdb->get_charset_collate();
  $sql             = "CREATE TABLE $table_name (
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
    update_option('alfa_business_cache_buster', time());
    echo '<div class="updated"><p>Caché vaciada correctamente.</p></div>';
  }

  if (isset($_POST['alfa_business_submit'])) {
    if (! isset($_POST['alfa_business_nonce']) || ! wp_verify_nonce($_POST['alfa_business_nonce'], 'alfa_business_save')) {
      echo '<div class="error"><p>Error de seguridad. Inténtalo de nuevo.</p></div>';
    } else {
      $token      = sanitize_text_field($_POST['alfa_business_token']);
      $uid_vendor = sanitize_text_field($_POST['alfa_business_uid_vendor']);
      $registro   = $wpdb->get_row("SELECT * FROM $table_name LIMIT 1");
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
      $resultado      = $wpdb->query("DROP TABLE IF EXISTS $tracking_table");
      if ($resultado !== false) {
        su_crear_tabla_seguimiento();
        echo '<div class="updated"><p>La tabla de seguimiento ha sido eliminada y recreada correctamente.</p></div>';
      } else {
        echo '<div class="error"><p>Error al borrar la tabla de seguimiento.</p></div>';
      }
    }
  }
  $registro           = $wpdb->get_row("SELECT * FROM $table_name LIMIT 1");
  $current_token      = $registro ? $registro->token : '';
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
    // Mostrar sección de Redes Sociales (RRSS)
    alfa_business_rrss_settings_section();

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
  $table_name      = $wpdb->prefix . 'alfa_business_locations';
  $charset_collate = $wpdb->get_charset_collate();
  $sql             = "CREATE TABLE $table_name (
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
    $result    = $wpdb->delete($table_name, array('id' => $record_id), array('%d'));
    if ($result !== false) {
      echo '<div class="updated"><p>Ubicación eliminada correctamente.</p></div>';
    } else {
      echo '<div class="error"><p>Error al eliminar la ubicación.</p></div>';
    }
  }
  if (isset($_POST['update_location']) && ! empty($_POST['location_record_id'])) {
    $record_id = intval($_POST['location_record_id']);
    $data      = array(
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
    $format    = array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s');
    $result    = $wpdb->update($table_name, $data, array('id' => $record_id), $format, array('%d'));
    if ($result !== false) {
      wp_cache_flush();
      echo '<div class="updated"><p>Ubicación actualizada correctamente.</p></div>';
    } else {
      echo '<div class="error"><p>Error al actualizar la ubicación.</p></div>';
    }
  }
  if (isset($_POST['alfa_business_add_location'])) {
    $data   = array(
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
  $table_name  = $wpdb->prefix . 'alfa_business_locations';
  $editing     = false;
  $edit_record = null;
  if (isset($_GET['action']) && $_GET['action'] == 'edit_location' && isset($_GET['record_id'])) {
    $record_id  = intval($_GET['record_id']);
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
          <td><input type="text" name="location_id" id="location_id" placeholder="ej: caracol" class="regular-text" disabled value="<?php echo $editing ? esc_attr($edit_record['location_id']) : ''; ?>"></td>
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
        <?php submit_button('Actualizar Ubicación', 'primary', 'update_location', false); ?>
        <a href="<?php echo admin_url('admin.php?page=alfa-business-p'); ?>" class="button">Cancelar Edición</a>
      <?php else : ?>
        <?php submit_button('Agregar Ubicación', 'primary', 'alfa_business_add_location', false); ?>
      <?php endif; ?>
    </form>
    <hr>
    <h2>Listado de Ubicaciones</h2>
    <form method="post" action="">
      <?php wp_nonce_field('alfa_business_save_locations', 'alfa_business_locations_nonce'); ?>
      <?php submit_button('Resetear Tabla de Ubicaciones', 'secondary', 'reset_locations_table', false); ?>
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

  /* ============================================================================
   SECTION 5: CONFIGURACIÓN DE REDES SOCIALES
============================================================================ */
  /**
   * Crea la tabla de Redes Sociales.
   */
  function alfa_business_create_rrss_table()
  {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'alfa_business_rrss';
    $charset_collate = $wpdb->get_charset_collate();
    $sql             = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        descripcion VARCHAR(100) NOT NULL,
        valor TEXT NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    error_log("Tabla $table_name creada/actualizada para Redes Sociales.");
  }
  register_activation_hook(__FILE__, 'alfa_business_create_rrss_table');

  /**
   * Procesa las acciones del formulario para Redes Sociales.
   */
  function alfa_business_rrss_actions_handler()
  {
    global $wpdb;
    $table_name = $wpdb->prefix . 'alfa_business_rrss';

    if (isset($_POST['alfa_business_rrss_nonce']) && ! wp_verify_nonce($_POST['alfa_business_rrss_nonce'], 'alfa_business_save_rrss')) {
      echo '<div class="error"><p>Error de seguridad en Redes Sociales. Inténtalo de nuevo.</p></div>';
      return;
    }

    // Eliminar registro
    if (isset($_POST['delete_rrss']) && ! empty($_POST['rrss_record_id'])) {
      $record_id = intval($_POST['rrss_record_id']);
      $result    = $wpdb->delete($table_name, array('id' => $record_id), array('%d'));
      if ($result !== false) {
        echo '<div class="updated"><p>Red social eliminada correctamente.</p></div>';
      } else {
        echo '<div class="error"><p>Error al eliminar la red social.</p></div>';
      }
    }

    // Actualizar registro
    if (isset($_POST['update_rrss']) && ! empty($_POST['rrss_record_id'])) {
      $record_id = intval($_POST['rrss_record_id']);
      $data      = array(
        'descripcion' => sanitize_text_field($_POST['descripcion']),
        'valor'       => esc_url_raw($_POST['valor'])
      );
      $format    = array('%s', '%s');
      $result    = $wpdb->update($table_name, $data, array('id' => $record_id), $format, array('%d'));
      if ($result !== false) {
        echo '<div class="updated"><p>Red social actualizada correctamente.</p></div>';
      } else {
        echo '<div class="error"><p>Error al actualizar la red social.</p></div>';
      }
    }

    // Agregar registro
    if (isset($_POST['alfa_business_add_rrss'])) {
      $data   = array(
        'descripcion' => sanitize_text_field($_POST['descripcion']),
        'valor'       => esc_url_raw($_POST['valor'])
      );
      $format = array('%s', '%s');
      $result = $wpdb->insert($table_name, $data, $format);
      if ($result !== false) {
        echo '<div class="updated"><p>Red social agregada correctamente.</p></div>';
      } else {
        echo '<div class="error"><p>Error al agregar la red social.</p></div>';
      }
    }

    // Resetear la tabla
    if (isset($_POST['reset_rrss_table'])) {
      $result = $wpdb->query("TRUNCATE TABLE $table_name");
      if ($result !== false) {
        wp_cache_flush();
        echo '<div class="updated"><p>La tabla de Redes Sociales ha sido vaciada correctamente.</p></div>';
      } else {
        echo '<div class="error"><p>Error al vaciar la tabla de Redes Sociales.</p></div>';
      }
    }
  }
  add_action('admin_init', 'alfa_business_rrss_actions_handler');

  /**
   * Muestra el formulario y la lista de Redes Sociales en el panel de administración.
   */
  function alfa_business_rrss_settings_section()
  {
    global $wpdb;
    $table_name  = $wpdb->prefix . 'alfa_business_rrss';
    $editing     = false;
    $edit_record = null;
    if (isset($_GET['action']) && $_GET['action'] == 'edit_rrss' && isset($_GET['record_id'])) {
      $record_id  = intval($_GET['record_id']);
      $edit_record = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $record_id), ARRAY_A);
      if ($edit_record) {
        $editing = true;
      }
    }
    ?>
    <div class="wrap">
      <hr>
      <h2><?php echo $editing ? "Editar Red Social" : "Agregar Red Social"; ?></h2>
      <form method="post" action="">
        <?php wp_nonce_field('alfa_business_save_rrss', 'alfa_business_rrss_nonce'); ?>
        <?php if ($editing) : ?>
          <table class="form-table">
            <tr>
              <th scope="row"><label for="rrss_id">ID</label></th>
              <td>
                <input type="text" id="rrss_id" value="<?php echo esc_attr($edit_record['id']); ?>" disabled>
                <input type="hidden" name="rrss_record_id" value="<?php echo esc_attr($edit_record['id']); ?>">
              </td>
            </tr>
          <?php endif; ?>
          <table class="form-table">
            <tr>
              <th scope="row"><label for="descripcion">Descripción</label></th>
              <td>
                <input type="text" name="descripcion" id="descripcion" placeholder="Ej: Facebook, WhatsApp, etc." class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['descripcion']) : ''; ?>">
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="valor">Valor (URL)</label></th>
              <td>
                <input type="url" name="valor" id="valor" placeholder="https://www.facebook.com/tuempresa" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['valor']) : ''; ?>">
              </td>
            </tr>
          </table>
          <?php if ($editing) : ?>
            <?php submit_button('Actualizar Red Social', 'primary', 'update_rrss', false); ?>
            <a href="<?php echo admin_url('admin.php?page=alfa-business-p'); ?>" class="button">Cancelar Edición</a>
          <?php else : ?>
            <?php submit_button('Agregar Red Social', 'primary', 'alfa_business_add_rrss', false); ?>
          <?php endif; ?>
      </form>
      <hr>
      <h2>Listado de Redes Sociales</h2>
      <form method="post" action="">
        <?php wp_nonce_field('alfa_business_save_rrss', 'alfa_business_rrss_nonce'); ?>
        <?php submit_button('Resetear Tabla de Redes Sociales', 'secondary', 'reset_rrss_table', false); ?>
      </form>
      <?php
      $rrss = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);
      if ($rrss) {
      ?>
        <table class="wp-list-table widefat fixed striped">
          <thead>
            <tr>
              <th>ID</th>
              <th>Descripción</th>
              <th>Valor (URL)</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rrss as $item) : ?>
              <tr>
                <td><?php echo esc_html($item['id']); ?></td>
                <td><?php echo esc_html($item['descripcion']); ?></td>
                <td><?php echo esc_html($item['valor']); ?></td>
                <td>
                  <form method="post" style="display:inline;">
                    <?php wp_nonce_field('alfa_business_save_rrss', 'alfa_business_rrss_nonce'); ?>
                    <input type="hidden" name="rrss_record_id" value="<?php echo esc_attr($item['id']); ?>">
                    <input type="submit" name="delete_rrss" class="button" value="Eliminar" onclick="return confirm('¿Seguro que deseas eliminar esta red social?');">
                  </form>
                  <a href="<?php echo admin_url('admin.php?page=alfa-business-p&action=edit_rrss&record_id=' . intval($item['id'])); ?>" class="button">Editar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php
      } else {
        echo '<p>No se han registrado redes sociales.</p>';
      }
      ?>
    </div>
  <?php
  }
  ?>