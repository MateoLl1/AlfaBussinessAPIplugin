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

// Endpoint: /alfabusiness/api/v1/web
add_action('rest_api_init', function () {
  register_rest_route('alfabusiness/api/v1', '/web', [
    'methods'  => 'GET',
    'callback' => 'get_web_data',
  ]);
});

function get_web_data(WP_REST_Request $request)
{
  $uid = $request->get_param('uid');

  // 1. Páginas y Entradas
  $items = get_posts([
    'post_type'   => ['page', 'post'],
    'post_status' => 'publish',
    'numberposts' => -1,
  ]);
  $paginas_y_entradas = [];
  foreach ($items as $item) {
    $raw = strip_shortcodes($item->post_content);
    $raw = preg_replace('/\[[^\]]*\]/', '', $raw);
    $clean = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($raw)));
    $paginas_y_entradas[] = [
      'Tipo'      => $item->post_type === 'page' ? 'Página' : 'Entrada',
      'Nombre'    => get_the_title($item->ID),
      'URL'       => append_uid_to_url(get_permalink($item->ID), $uid),
      'Contenido' => $clean,
    ];
  }

  // 2. Categorías de Productos
  $cat_data = [];
  if (taxonomy_exists('product_cat')) {
    foreach (get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]) as $cat) {
      $cat_data[] = [
        'ID'     => $cat->term_id,
        'Nombre' => $cat->name,
        'Slug'   => $cat->slug,
        'Padre'  => $cat->parent,
      ];
    }
  }

  // 3. Sucursales (CPT 'sucursal')
  $sucursal_data = [];
  if (post_type_exists('sucursal')) {
    foreach (get_posts(['post_type' => 'sucursal', 'post_status' => 'publish', 'numberposts' => -1]) as $suc) {
      $raw = strip_shortcodes($suc->post_content);
      $raw = preg_replace('/\[[^\]]*\]/', '', $raw);
      $clean = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($raw)));
      $sucursal_data[] = [
        'Nombre'    => get_the_title($suc->ID),
        'URL'       => append_uid_to_url(get_permalink($suc->ID), $uid),
        'Contenido' => $clean,
      ];
    }
  }

  // 4. Brands de Productos
  $brand_data = [];
  if (taxonomy_exists('product_brand')) {
    foreach (get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false]) as $br) {
      $brand_data[] = [
        'ID'     => $br->term_id,
        'Nombre' => $br->name,
        'Slug'   => $br->slug,
        'Padre'  => $br->parent,
      ];
    }
  }

  // 5. Respuesta final
  $data = [
    'paginas_y_entradas'   => $paginas_y_entradas,
    'categorias_productos' => $cat_data,
    'sucursales'           => $sucursal_data,
    'brands'               => $brand_data,
  ];

  return rest_ensure_response(['Datos' => $data]);
}


// Endpoint: /alfabusiness/api/v1/keywords/commerce

add_action('rest_api_init', function () {
  register_rest_route('alfabusiness/api/v1', 'keywords/commerce', array(
    'methods'  => 'GET',
    'callback' => 'get_product_keywords',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});

function get_product_keywords(WP_REST_Request $request)
{
  // Obtener todos los productos publicados
  $args = array(
    'post_type'   => 'product',
    'post_status' => 'publish',
    'numberposts' => -1,
  );
  $products = get_posts($args);
  $keywords = array();

  // Stopwords comunes en español (puedes ampliarla)
  $stopwords = array(
    'de',
    'la',
    'y',
    'en',
    'el',
    'a',
    'los',
    'del',
    'se',
    'por',
    'con',
    'un',
    'una',
    'al',
    'para',
    'es',
    'como',
    'más',
    'o',
    'u',
    'e',
    'las',
    'todo',
    'toda',
    'todos',
    'todas'
  );

  // Lista de patrones a descartar (técnicos, de builder, etc.)
  $blacklist = array(
    'builderversion',
    'modulepresetdefault',
    'globalcolorsinfo',
    'etpb',
    'adminlabel',
    'custompadding',
    'background',
    'borderwidth',
    'disabled',
    'modulealignment',
    'maxwidth',
    'showtags',
    'moduleid',
    'moduleclass',
    'columnstructure',
    'fonticon',
    'collapsed',
    'stickyenabled',
    'hoverenabled'
  );

  // Recorrer cada producto
  foreach ($products as $product) {
    // Combinar título y contenido
    $text = get_the_title($product->ID) . ' ' . $product->post_content;
    // Limpiar etiquetas HTML, convertir a minúsculas y eliminar puntuación
    $text = strtolower(strip_tags($text));
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
    // Separar en palabras (filtrando espacios múltiples)
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($words as $word) {
      // Ignorar stopwords o palabras muy cortas (menos de 3 caracteres)
      if (in_array($word, $stopwords) || strlen($word) < 3) {
        continue;
      }
      // Ignorar palabras excesivamente largas (más de 30 caracteres)
      if (strlen($word) > 30) {
        continue;
      }
      // Verificar si la palabra contiene alguno de los patrones de la blacklist
      $skip = false;
      foreach ($blacklist as $pattern) {
        if (stripos($word, $pattern) !== false) {
          $skip = true;
          break;
        }
      }
      if ($skip) {
        continue;
      }
      // Contar la frecuencia de cada palabra (aunque luego no se usará)
      if (isset($keywords[$word])) {
        $keywords[$word]++;
      } else {
        $keywords[$word] = 1;
      }
    }
  }

  // Ordenar las keywords por frecuencia descendente
  arsort($keywords);
  // Extraer únicamente la lista de palabras (claves)
  $keyword_list = array_keys($keywords);

  return new WP_REST_Response(array('keywords' => $keyword_list), 200);
}


// Endpoint: /alfabusiness/api/v1/general/keywords
add_action('rest_api_init', function () {
  register_rest_route('alfabusiness/api/v1', '/general/keywords', array(
    'methods'  => 'GET',
    'callback' => 'get_page_keywords',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});

function get_page_keywords(WP_REST_Request $request)
{
  // Obtener todas las páginas publicadas
  $args = array(
    'post_type'   => 'page',
    'post_status' => 'publish',
    'numberposts' => -1,
  );
  $pages = get_posts($args);
  $keywords = array();

  // Stopwords comunes en español (puedes ampliarla)
  $stopwords = array(
    'de',
    'la',
    'y',
    'en',
    'el',
    'a',
    'los',
    'del',
    'se',
    'por',
    'con',
    'un',
    'una',
    'al',
    'para',
    'es',
    'como',
    'más',
    'o',
    'u',
    'e',
    'las',
    'todo',
    'toda',
    'todos',
    'todas'
  );

  // Lista de patrones a descartar (palabras técnicas o del constructor)
  $blacklist = array(
    'builderversion',
    'modulepresetdefault',
    'globalcolorsinfo',
    'etpb',
    'adminlabel',
    'custompadding',
    'background',
    'borderwidth',
    'disabled',
    'modulealignment',
    'maxwidth',
    'showtags',
    'moduleid',
    'moduleclass',
    'columnstructure',
    'fonticon',
    'collapsed',
    'stickyenabled',
    'hoverenabled'
  );

  // Recorrer cada página
  foreach ($pages as $page) {
    // Combinar título y contenido para extraer las keywords
    $text = get_the_title($page->ID) . ' ' . $page->post_content;
    // Limpiar etiquetas HTML, convertir a minúsculas y eliminar signos de puntuación
    $text = strtolower(strip_tags($text));
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
    // Separar en palabras
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($words as $word) {
      // Ignorar stopwords y palabras muy cortas (menos de 3 caracteres)
      if (in_array($word, $stopwords) || strlen($word) < 3) {
        continue;
      }
      // Ignorar palabras demasiado largas (más de 30 caracteres)
      if (strlen($word) > 30) {
        continue;
      }
      // Descartar palabras que contengan patrones de la blacklist
      $skip = false;
      foreach ($blacklist as $pattern) {
        if (stripos($word, $pattern) !== false) {
          $skip = true;
          break;
        }
      }
      if ($skip) {
        continue;
      }
      // Contar la palabra (aunque solo se usará para filtrar y ordenar)
      if (isset($keywords[$word])) {
        $keywords[$word]++;
      } else {
        $keywords[$word] = 1;
      }
    }
  }

  // Ordenar las keywords por frecuencia descendente
  arsort($keywords);
  // Extraer solo la lista de palabras (sin la frecuencia)
  $keyword_list = array_keys($keywords);

  return new WP_REST_Response(array('keywords' => $keyword_list), 200);
}




function get_general_keywords(WP_REST_Request $request)
{
  $keywords = array();

  // Stopwords comunes en español (puedes ampliarla)
  $stopwords = array(
    'de',
    'la',
    'y',
    'en',
    'el',
    'a',
    'los',
    'del',
    'se',
    'por',
    'con',
    'un',
    'una',
    'al',
    'para',
    'es',
    'como',
    'más',
    'o',
    'u',
    'e',
    'las',
    'todo',
    'toda',
    'todos',
    'todas'
  );

  // Lista de patrones a descartar (palabras técnicas o del constructor)
  $blacklist = array(
    'builderversion',
    'modulepresetdefault',
    'globalcolorsinfo',
    'etpb',
    'adminlabel',
    'custompadding',
    'background',
    'borderwidth',
    'disabled',
    'modulealignment',
    'maxwidth',
    'showtags',
    'moduleid',
    'moduleclass',
    'columnstructure',
    'fonticon',
    'collapsed',
    'stickyenabled',
    'hoverenabled'
  );

  // Función interna para procesar texto y actualizar el arreglo de keywords
  $process_text = function ($text) use (&$keywords, $stopwords, $blacklist) {
    // Combinar título y contenido ya debe venir concatenado
    $text = strtolower(strip_tags($text));
    // Eliminar puntuación (dejando solo letras y números)
    $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
    // Separar en palabras (filtrando espacios múltiples)
    $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

    foreach ($words as $word) {
      // Ignorar stopwords y palabras muy cortas (menos de 3 caracteres)
      if (in_array($word, $stopwords) || strlen($word) < 3) {
        continue;
      }
      // Ignorar palabras excesivamente largas (más de 30 caracteres)
      if (strlen($word) > 30) {
        continue;
      }
      // Descartar palabras que contengan alguno de los patrones de la blacklist
      $skip = false;
      foreach ($blacklist as $pattern) {
        if (stripos($word, $pattern) !== false) {
          $skip = true;
          break;
        }
      }
      if ($skip) {
        continue;
      }
      // Contar la palabra (para luego ordenar, aunque finalmente solo devolveremos la lista)
      if (isset($keywords[$word])) {
        $keywords[$word]++;
      } else {
        $keywords[$word] = 1;
      }
    }
  };

  // Obtener todas las páginas publicadas
  $pages = get_posts(array(
    'post_type'   => 'page',
    'post_status' => 'publish',
    'numberposts' => -1,
  ));

  // Procesar texto de cada página
  foreach ($pages as $page) {
    $text = get_the_title($page->ID) . ' ' . $page->post_content;
    $process_text($text);
  }

  // Obtener todos los productos publicados
  $products = get_posts(array(
    'post_type'   => 'product',
    'post_status' => 'publish',
    'numberposts' => -1,
  ));

  // Procesar texto de cada producto
  foreach ($products as $product) {
    $text = get_the_title($product->ID) . ' ' . $product->post_content;
    $process_text($text);
  }

  // Ordenar las keywords por frecuencia descendente (opcional)
  arsort($keywords);
  // Extraer únicamente la lista de palabras (sin la frecuencia)
  $keyword_list = array_keys($keywords);

  return new WP_REST_Response(array('keywords' => $keyword_list), 200);
}



/**
 * Función auxiliar para limpiar el contenido.
 * Elimina shortcodes, etiquetas HTML y normaliza los espacios para devolver solo texto plano.
 */
function clean_content($raw_content)
{
  if (empty($raw_content)) {
    return '';
  }
  // Quitar shortcodes registrados
  $content = strip_shortcodes($raw_content);
  // Remover shortcodes anidados o no registrados mediante regex
  $content = preg_replace('/\[(\/?)[^\]]+\]/', '', $content);
  // Eliminar todas las etiquetas HTML
  $content = wp_strip_all_tags($content);
  // Normalizar espacios
  $content = trim(preg_replace('/\s+/', ' ', $content));
  return $content;
}

/**
 * Función auxiliar para agregar el parámetro uid a una URL.
 * Si se recibe un valor en $uid, se adjunta a la URL usando el separador adecuado.
 */
function append_uid_to_url($url, $uid)
{
  if (!empty($uid)) {
    $separator = (strpos($url, '?') !== false) ? '&' : '?';
    $url .= $separator . 'uid=' . urlencode($uid);
  }
  return $url;
}


//Endpoint: /alfabusiness/api/v1/search/products

add_action('rest_api_init', function () {
  register_rest_route('alfabusiness/api/v1', '/search/products', array(
    'methods'             => 'GET',
    'callback'            => 'search_products_by_keywords',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});

function search_products_by_keywords(WP_REST_Request $request)
{
  // Obtener parámetros
  $keywords_param   = $request->get_param('keywords');
  $categories_param = $request->get_param('categories'); // Nuevo parámetro
  $limit_param      = $request->get_param('limit');
  $uid_param        = $request->get_param('uid');

  if (empty($keywords_param)) {
    return new WP_Error('missing_keywords', 'El parámetro keywords es obligatorio.', array('status' => 400));
  }

  // Procesar keywords
  $keywords_array = array_filter(array_map('trim', explode(',', strtolower($keywords_param))));

  // Procesar categorías (opcional)
  $categories_array = array();
  if (!empty($categories_param)) {
    $categories_array = array_filter(array_map('trim', explode(',', strtolower($categories_param))));
  }

  // Construir args para get_posts
  $args = array(
    'post_type'   => 'product',
    'post_status' => 'publish',
    'numberposts' => -1,
  );

  // Si especificaron categorías, filtramos por product_cat
  if (!empty($categories_array)) {
    $args['tax_query'] = array(
      array(
        'taxonomy' => 'product_cat',
        'field'    => 'slug',      // usa 'term_id' si envías IDs
        'terms'    => $categories_array,
      ),
    );
  }

  // Obtener productos
  $products = get_posts($args);
  $results  = array();

  foreach ($products as $product) {
    $product_id    = $product->ID;
    $title         = get_the_title($product_id);
    $plain_content = clean_content($product->post_content);
    $text          = strtolower($title . ' ' . $plain_content);

    // Contar coincidencias de keywords
    $match_count = 0;
    foreach ($keywords_array as $kw) {
      $match_count += substr_count($text, $kw);
    }

    if ($match_count > 0) {
      // Datos adicionales de WooCommerce
      $additional_data = array();
      if (function_exists('wc_get_product')) {
        $wc = wc_get_product($product_id);
        if ($wc) {
          $additional_data = array(
            'short_description' => wp_strip_all_tags($wc->get_short_description()),
            'price'             => $wc->get_price(),
            'regular_price'     => $wc->get_regular_price(),
            'sale_price'        => $wc->get_sale_price(),
            'sku'               => $wc->get_sku(),
            'image'             => get_the_post_thumbnail_url($product_id, 'full'),
          );
        }
      }

      $results[] = array_merge(array(
        'ID'          => $product_id,
        'title'       => $title,
        'url'         => append_uid_to_url(get_permalink($product_id), $uid_param),
        'content'     => $plain_content,
        'match_count' => $match_count,
      ), $additional_data);
    }
  }

  // Ordenar y limitar
  usort($results, function ($a, $b) {
    return $b['match_count'] - $a['match_count'];
  });
  $limit   = $limit_param ? intval($limit_param) : 10;
  $results = array_slice($results, 0, $limit);

  return new WP_REST_Response(array('results' => $results), 200);
}




// Endpoint: /alfabusiness/api/v1/search/pages

add_action('rest_api_init', function () {
  register_rest_route('alfabusiness/api/v1', '/search/pages', array(
    'methods'             => 'GET',
    'callback'            => 'search_pages_by_keywords',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});


function search_pages_by_keywords(WP_REST_Request $request)
{
  $keywords_param = $request->get_param('keywords');
  $limit_param    = $request->get_param('limit');
  $uid_param      = $request->get_param('uid');

  if (empty($keywords_param)) {
    return new WP_Error('missing_keywords', 'El parámetro keywords es obligatorio.', array('status' => 400));
  }

  $keywords_array = array_map('trim', explode(',', $keywords_param));
  $keywords_array = array_map('strtolower', $keywords_array);
  $limit = $limit_param ? intval($limit_param) : 10;

  $pages = get_posts(array(
    'post_type'   => 'page',
    'post_status' => 'publish',
    'numberposts' => -1,
  ));

  $results = array();

  foreach ($pages as $page) {
    $page_id = $page->ID;
    $title   = get_the_title($page_id);
    $raw_content = get_post_field('post_content', $page_id);
    $plain_content = clean_content($raw_content);

    // Datos opcionales: excerpt limpiado
    $optional = array();
    $excerpt = get_the_excerpt($page_id);
    if (!empty($excerpt)) {
      $optional['excerpt'] = clean_content($excerpt);
    }
    $text = strtolower($title . ' ' . $plain_content);
    $match_count = 0;
    foreach ($keywords_array as $keyword) {
      if (!empty($keyword)) {
        $match_count += substr_count($text, $keyword);
      }
    }
    if ($match_count > 0) {
      $results[] = array_merge(array(
        'ID'          => $page_id,
        'title'       => $title,
        'url'         => append_uid_to_url(get_permalink($page_id), $uid_param),
        'content'     => $plain_content,
        'match_count' => $match_count
      ), $optional);
    }
  }

  usort($results, function ($a, $b) {
    return $b['match_count'] - $a['match_count'];
  });
  $results = array_slice($results, 0, $limit);

  return new WP_REST_Response(array('results' => $results), 200);
}


// Endpoint: /alfabusiness/api/v1/search/general

add_action('rest_api_init', function () {
  register_rest_route('alfabusiness/api/v1', '/search/general', array(
    'methods'             => 'GET',
    'callback'            => 'search_general_by_keywords',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});

function search_general_by_keywords(WP_REST_Request $request)
{
  $keywords_param = $request->get_param('keywords');
  $limit_param    = $request->get_param('limit');
  $uid_param      = $request->get_param('uid');

  if (empty($keywords_param)) {
    return new WP_Error('missing_keywords', 'El parámetro keywords es obligatorio.', array('status' => 400));
  }

  $keywords_array = array_map('trim', explode(',', $keywords_param));
  $keywords_array = array_map('strtolower', $keywords_array);
  $limit = $limit_param ? intval($limit_param) : 10;

  $results = array();
  $post_types = array('product', 'page');

  foreach ($post_types as $post_type) {
    $posts = get_posts(array(
      'post_type'   => $post_type,
      'post_status' => 'publish',
      'numberposts' => -1,
    ));
    foreach ($posts as $post) {
      $post_id = $post->ID;
      $title   = get_the_title($post_id);
      $raw_content = $post->post_content;
      $plain_content = clean_content($raw_content);
      $optional = array();
      if ($post_type === 'product') {
        if (function_exists('wc_get_product')) {
          $wc_product = wc_get_product($post_id);
          if ($wc_product) {
            $optional = array(
              'short_description' => wp_strip_all_tags($wc_product->get_short_description()),
              'price'             => $wc_product->get_price(),
              'regular_price'     => $wc_product->get_regular_price(),
              'sale_price'        => $wc_product->get_sale_price(),
              'sku'               => $wc_product->get_sku(),
              'image'             => get_the_post_thumbnail_url($post_id, 'full')
            );
          }
        }
      } elseif ($post_type === 'page') {
        $excerpt = get_the_excerpt($post_id);
        if (!empty($excerpt)) {
          $optional['excerpt'] = clean_content($excerpt);
        }
      }
      $text = strtolower($title . ' ' . $plain_content);
      $match_count = 0;
      foreach ($keywords_array as $keyword) {
        if (!empty($keyword)) {
          $match_count += substr_count($text, $keyword);
        }
      }
      if ($match_count > 0) {
        $results[] = array_merge(array(
          'ID'          => $post_id,
          'title'       => $title,
          'url'         => append_uid_to_url(get_permalink($post_id), $uid_param),
          'post_type'   => $post_type,
          'content'     => $plain_content,
          'match_count' => $match_count
        ), $optional);
      }
    }
  }
  usort($results, function ($a, $b) {
    return $b['match_count'] - $a['match_count'];
  });
  $results = array_slice($results, 0, $limit);

  return new WP_REST_Response(array('results' => $results), 200);
}


// Función auxiliar para eliminar claves con valor 0 recursivamente
function eliminar_valores_cero($data)
{
  if (is_array($data)) {
    foreach ($data as $key => $value) {
      // Si es un array, aplicar recursividad
      if (is_array($value)) {
        $data[$key] = eliminar_valores_cero($value);
        // Si el array resultante está vacío, se elimina la clave
        if (empty($data[$key])) {
          unset($data[$key]);
        }
      } else {
        // Si el valor es exactamente 0 o 0.0, se elimina la clave
        if ($value === 0 || $value === 0.0) {
          unset($data[$key]);
        }
      }
    }
  }
  return $data;
}

// Endpoint: /alfabusiness/api/v1/metrics
add_action('rest_api_init', function () {
  register_rest_route('alfabusiness/api/v1', '/metrics', array(
    'methods'             => 'GET',
    'callback'            => 'get_metrics_data',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});

function get_metrics_data(WP_REST_Request $request)
{
  global $wpdb;

  // ----------------------------
  // Métricas de la Web
  // ----------------------------
  $post_count = wp_count_posts('post');
  $page_count = wp_count_posts('page');

  $web_metrics = array(
    'posts'      => isset($post_count->publish) ? intval($post_count->publish) : 0,
    'pages'      => isset($page_count->publish) ? intval($page_count->publish) : 0,
  );

  // Número de categorías y etiquetas utilizadas
  $categories = get_terms(array(
    'taxonomy'   => 'category',
    'hide_empty' => true,
  ));
  $tags = get_terms(array(
    'taxonomy'   => 'post_tag',
    'hide_empty' => true,
  ));
  $web_metrics['categories'] = is_array($categories) ? count($categories) : 0;
  $web_metrics['tags']       = is_array($tags) ? count($tags) : 0;

  // Comentarios: aprobados y pendientes
  $comment_count = wp_count_comments();
  $web_metrics['comments'] = array(
    'approved' => isset($comment_count->approved) ? intval($comment_count->approved) : 0,
    'pending'  => isset($comment_count->awaiting_moderation) ? intval($comment_count->awaiting_moderation) : 0,
  );

  // Visitas: Obtener valores reales almacenados en la base de datos (se espera que el tracking los actualice)
  $visits_total  = get_option('alfa_business_visits_total', 0);
  $unique_visits = get_option('alfa_business_unique_visits', 0);
  $web_metrics['visits'] = array(
    'total'  => intval($visits_total),
    'unique' => intval($unique_visits)
  );

  // ----------------------------
  // Métricas del E-commerce
  // ----------------------------
  $ecommerce_metrics = array(
    'orders'          => 0,
    'total_sales'     => 0.0,
    'average_order'   => 0.0,
    'top_products'    => array(),
    'conversion_rate' => 0.0,
    'products'        => 0,
  );

  if (class_exists('WooCommerce')) {
    $orders = wc_get_orders(array(
      'limit'  => -1,
      'status' => array('wc-completed', 'wc-processing', 'wc-on-hold'),
    ));

    $order_count   = count($orders);
    $total_sales   = 0;
    $product_sales = array();

    foreach ($orders as $order) {
      $total_sales += floatval($order->get_total());
      foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $qty = $item->get_quantity();
        if (isset($product_sales[$product_id])) {
          $product_sales[$product_id] += $qty;
        } else {
          $product_sales[$product_id] = $qty;
        }
      }
    }

    $average_order = $order_count > 0 ? $total_sales / $order_count : 0;

    arsort($product_sales);
    $top_products = array();
    $top_limit = 5;
    $i = 0;
    foreach ($product_sales as $product_id => $sales) {
      if ($i >= $top_limit) {
        break;
      }
      $product = wc_get_product($product_id);
      $top_products[] = array(
        'product_id' => $product_id,
        'name'       => $product ? $product->get_name() : '',
        'sales'      => $sales,
      );
      $i++;
    }

    $visits_total_calc = intval($web_metrics['visits']['total']);
    $conversion_rate = ($visits_total_calc > 0) ? ($order_count / $visits_total_calc) * 100 : 0;

    $product_count = wp_count_posts('product');
    $products_published = isset($product_count->publish) ? intval($product_count->publish) : 0;

    $ecommerce_metrics = array(
      'orders'          => $order_count,
      'total_sales'     => round($total_sales, 2),
      'average_order'   => round($average_order, 2),
      'top_products'    => $top_products,
      'conversion_rate' => round($conversion_rate, 2),
      'products'        => $products_published,
    );
  }

  // ----------------------------
  // Métricas de Tracking
  // Separamos por uid y ga_uid
  // ----------------------------
  $tabla_tracking = $wpdb->prefix . 'seguimiento_usuario';
  $today_start = date('Y-m-d') . " 00:00:00";

  // Métricas basadas en la columna uid
  $registered_uids = $wpdb->get_col("SELECT DISTINCT uid FROM {$tabla_tracking} WHERE uid IS NOT NULL AND uid <> ''");
  $registered_uid_count = is_array($registered_uids) ? count($registered_uids) : 0;

  $today_uids = $wpdb->get_col(
    $wpdb->prepare(
      "SELECT DISTINCT uid FROM {$tabla_tracking} WHERE fecha >= %s AND uid IS NOT NULL AND uid <> ''",
      $today_start
    )
  );
  $today_uid_count = is_array($today_uids) ? count($today_uids) : 0;

  // Métricas basadas en la columna ga_uid
  $registered_ga = $wpdb->get_col("SELECT DISTINCT ga_uid FROM {$tabla_tracking} WHERE ga_uid IS NOT NULL AND ga_uid <> ''");
  $registered_ga_count = is_array($registered_ga) ? count($registered_ga) : 0;

  $today_ga = $wpdb->get_col(
    $wpdb->prepare(
      "SELECT DISTINCT ga_uid FROM {$tabla_tracking} WHERE fecha >= %s AND ga_uid IS NOT NULL AND ga_uid <> ''",
      $today_start
    )
  );
  $today_ga_count = is_array($today_ga) ? count($today_ga) : 0;

  $tracking_metrics = array(
    'uid_metrics' => array(
      'registered_users_count' => $registered_uid_count,
      'today_users_count'      => $today_uid_count,
      'today_users'            => $today_uids,
    ),
    'ga_metrics' => array(
      'registered_users_count' => $registered_ga_count,
      'today_users_count'      => $today_ga_count,
      'today_users'            => $today_ga,
    ),
  );

  // ----------------------------
  // Combinar todas las métricas en dos grandes bloques: web y tracking
  // ----------------------------
  $data = array(
    'web'      => array(
      'web_info'  => $web_metrics,
      'ecommerce' => $ecommerce_metrics,
    ),
    'tracking' => $tracking_metrics,
  );

  // Eliminar claves con valor 0 de forma recursiva
  $data = eliminar_valores_cero($data);

  return new WP_REST_Response($data, 200);
}



// Endpoint: /alfabusiness/api/v1/rrss
add_action('rest_api_init', function () {
  register_rest_route('alfabusiness/api/v1', '/rrss', array(
    'methods'             => 'GET',
    'callback'            => 'alfa_business_get_rrss',
    'permission_callback' => 'alfa_business_permission_callback'
  ));
});

function alfa_business_get_rrss(WP_REST_Request $request)
{
  global $wpdb;
  $table_name = $wpdb->prefix . 'alfa_business_rrss';
  $results    = $wpdb->get_results("SELECT SQL_NO_CACHE * FROM {$table_name}", ARRAY_A);

  $response = new WP_REST_Response($results, 200);
  $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
  $response->header('Pragma', 'no-cache');
  $response->header('Expires', '0');

  return $response;
}

add_action('rest_api_init', 'alfa_business_register_locations_endpoint');
function alfa_business_register_locations_endpoint()
{
  register_rest_route('alfabusiness/api/v1', '/sucursales', array(
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



// Endpoint: /alfabusiness/api/v1/products
add_action('rest_api_init', function () {
  register_rest_route(
    'alfabusiness/api/v1',
    '/products',
    [
      'methods'             => 'GET',
      'callback'            => 'alfa_business_get_all_products',
      'permission_callback' => 'alfa_business_permission_callback',
    ]
  );
});

/**
 * Devuelve todos los productos publicados.
 * – Si WooCommerce no está activo, retorna [].
 * – Sin paginación (tú la puedes añadir luego con ?page=&per_page=).
 */
function alfa_business_get_all_products(WP_REST_Request $request)
{
  // Si WooCommerce no existe → arreglo vacío
  if (! class_exists('WooCommerce')) {
    return new WP_REST_Response([], 200);
  }

  // --- parámetros opcionales ---
  $page     = max(1, (int) $request->get_param('page'));
  $per_page = min(100, (int) $request->get_param('per_page') ?: 100);
  $offset   = ($page - 1) * $per_page;

  // Traer productos publicados
  $query = new WP_Query([
    'post_type'      => 'product',
    'post_status'    => 'publish',
    'posts_per_page' => $per_page,
    'offset'         => $offset,
    'fields'         => 'ids', // sólo IDs para eficiencia
  ]);

  if (! $query->have_posts()) {
    return new WP_REST_Response([], 200);
  }

  $productos = [];
  foreach ($query->posts as $product_id) {
    $wc_product = wc_get_product($product_id);
    if (! $wc_product) {
      continue;
    }

    $productos[] = [
      'ID'             => $product_id,
      'title'          => $wc_product->get_name(),
      'url'            => get_permalink($product_id),
      'short_desc'     => wp_strip_all_tags($wc_product->get_short_description()),
      'price'          => $wc_product->get_price(),
      'regular_price'  => $wc_product->get_regular_price(),
      'sale_price'     => $wc_product->get_sale_price(),
      'sku'            => $wc_product->get_sku(),
      'image'          => get_the_post_thumbnail_url($product_id, 'full'),
      'categories'     => wp_get_post_terms($product_id, 'product_cat', ['fields' => 'names']),
      'stock_status'   => $wc_product->get_stock_status(),
    ];
  }

  return new WP_REST_Response($productos, 200);
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
  // Se agregan las columnas: uid y ga_uid, además de las demás.
  if ($wpdb->get_var("SHOW TABLES LIKE '{$tabla}'") != $tabla) {
    $sql = "CREATE TABLE {$tabla} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            uid VARCHAR(255) DEFAULT NULL,
            ga_uid VARCHAR(255) DEFAULT NULL,
            url TEXT NOT NULL,
            parametros TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            referer TEXT DEFAULT NULL,
            device_type VARCHAR(50) DEFAULT NULL,
            location VARCHAR(255) DEFAULT NULL,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX(uid),
            INDEX(ga_uid)
        ) {$charset_collate};";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    error_log("Tabla {$tabla} creada para seguimiento de usuarios.");
  } else {
    error_log("Tabla {$tabla} ya existe.");
  }
}


// (2) Captura el parámetro uid desde GET, establece la cookie y registra la visita.
function su_capturar_us_id()
{
  error_log("Ejecutando su_capturar_us_id(), GET: " . print_r($_GET, true));
  if (isset($_GET['uid']) && !empty($_GET['uid'])) {
    $uid = sanitize_text_field($_GET['uid']);
    $current_url = home_url($_SERVER['REQUEST_URI']);
    $parametros = $_GET;
    error_log("Capturado uid: {$uid} en URL: {$current_url}");
    su_guardar_visita($uid, null, $current_url, $parametros); // Solo se envía uid aquí
    // Establece la cookie para futuros registros (10 años)
    setcookie('su_uid', $uid, time() + (10 * YEAR_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
    $_COOKIE['su_uid'] = $uid;
  }
}
add_action('init', 'su_capturar_us_id', 1);


// (3) Registra actividad en cada carga de página utilizando ambas cookies (su_uid y _ga)
function su_registrar_actividad()
{
  $current_url = home_url($_SERVER['REQUEST_URI']);
  $parametros = $_GET;

  // Capturamos los valores (si existen)
  $uid = (isset($_COOKIE['su_uid']) && !empty($_COOKIE['su_uid'])) ? sanitize_text_field($_COOKIE['su_uid']) : null;
  $ga_uid = (isset($_COOKIE['_ga']) && !empty($_COOKIE['_ga'])) ? sanitize_text_field($_COOKIE['_ga']) : null;

  // Si al menos uno de los identificadores existe, se registra la visita.
  if ($uid || $ga_uid) {
    error_log("Registrando actividad en URL: {$current_url} para uid: {$uid} y ga_uid: {$ga_uid}");
    // Aquí podrías hacer una verificación para evitar registros duplicados si lo deseas.
    su_guardar_visita($uid, $ga_uid, $current_url, $parametros);
  } else {
    error_log("su_registrar_actividad: No se encontró ni la cookie su_uid ni _ga.");
  }
}
add_action('wp', 'su_registrar_actividad', 1);


// (4) Inserta un registro de visita en la base de datos, almacenando uid y ga_uid.
function su_guardar_visita($uid = null, $ga_uid = null, $url, $parametros)
{
  global $wpdb;
  $tabla = $wpdb->prefix . 'seguimiento_usuario';
  $ip_address = su_obtener_ip();
  $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
  $referer    = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : '';
  $device_type = su_determinar_tipo_dispositivo($user_agent);
  $location = su_obtener_ubicacion($ip_address);
  $parametros_json = !empty($parametros) ? wp_json_encode($parametros) : null;
  error_log("Ejecutando su_guardar_visita() para uid: {$uid} y ga_uid: {$ga_uid} en URL: {$url}");
  $resultado = $wpdb->insert(
    $tabla,
    array(
      'uid'         => $uid,
      'ga_uid'      => $ga_uid,
      'url'         => $url,
      'parametros'  => $parametros_json,
      'ip_address'  => $ip_address,
      'user_agent'  => $user_agent,
      'referer'     => $referer,
      'device_type' => $device_type,
      'location'    => $location,
      'fecha'       => current_time('mysql')
    ),
    array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
  );
  if ($resultado === false) {
    error_log("Error al insertar visita para uid: {$uid}. Error: " . $wpdb->last_error);
  } else {
    error_log("Visita registrada para uid: {$uid} y ga_uid: {$ga_uid} en URL: {$url}");
  }
}

// (5) Función para limpiar la tabla de seguimiento y crearla de nuevo,
//     insertando inmediatamente una nueva fila con los valores actuales de las cookies.
function su_limpiar_tabla_seguimiento()
{
  global $wpdb;
  $tabla = $wpdb->prefix . 'seguimiento_usuario';
  // Eliminar la tabla actual.
  $sql = "DROP TABLE IF EXISTS {$tabla}";
  $wpdb->query($sql);
  error_log("Tabla {$tabla} eliminada.");
  // Recrear la tabla.
  su_crear_tabla_seguimiento();
  // Registrar una nueva fila con las cookies actuales
  $uid = (isset($_COOKIE['su_uid']) && !empty($_COOKIE['su_uid'])) ? sanitize_text_field($_COOKIE['su_uid']) : null;
  $ga_uid = (isset($_COOKIE['_ga']) && !empty($_COOKIE['_ga'])) ? sanitize_text_field($_COOKIE['_ga']) : null;
  $current_url = home_url($_SERVER['REQUEST_URI']);
  $parametros = $_GET;
  error_log("Insertando registro inicial en tabla de seguimiento para uid: {$uid} y ga_uid: {$ga_uid} en URL: {$current_url}");
  su_guardar_visita($uid, $ga_uid, $current_url, $parametros);
}

// (6) Registro del endpoint REST para obtener historial (modificado para aceptar uid y ga opcionales)
function su_registrar_endpoint_api()
{
  register_rest_route('alfabusiness/api/v1', '/user', array(
    'methods'             => 'GET',
    'callback'            => 'su_obtener_historial',
    'args'                => array(
      'uid' => array(
        'required'          => false,
        'sanitize_callback' => 'sanitize_text_field',
      ),
      'ga' => array(
        'required'          => false,
        'sanitize_callback' => 'sanitize_text_field',
      ),
    ),
    'permission_callback' => 'alfa_business_permission_callback'
  ));
  error_log("Endpoint REST /alfabusiness/api/v1/user registrado.");
}
add_action('rest_api_init', 'su_registrar_endpoint_api');

function su_obtener_historial(WP_REST_Request $request)
{
  global $wpdb;
  $uid   = $request->get_param('uid');
  $ga    = $request->get_param('ga');
  $tabla = $wpdb->prefix . 'seguimiento_usuario';

  // Si no se envía ninguno de los parámetros, se retorna un error.
  if (empty($uid) && empty($ga)) {
    return new WP_Error('missing_param', 'Se requiere al menos uno de los parámetros: uid o ga.', array('status' => 400));
  }

  // Si se envían ambos parámetros, se buscan registros que coincidan en uid o en ga_uid.
  if (!empty($uid) && !empty($ga)) {
    $resultados = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$tabla} WHERE uid = %s OR ga_uid = %s ORDER BY fecha DESC", $uid, $ga),
      ARRAY_A
    );
  }
  // Si se envía solo uid
  elseif (!empty($uid)) {
    $resultados = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$tabla} WHERE uid = %s ORDER BY fecha DESC", $uid),
      ARRAY_A
    );
  }
  // Si se envía solo ga (corresponde al valor de _ga)
  else {
    $resultados = $wpdb->get_results(
      $wpdb->prepare("SELECT * FROM {$tabla} WHERE ga_uid = %s ORDER BY fecha DESC", $ga),
      ARRAY_A
    );
  }

  if (empty($resultados)) {
    error_log("No se encontró historial para uid: {$uid} y ga: {$ga}");
    return new WP_Error('no_data', 'No se encontró historial para el/los parámetros proporcionados.', array('status' => 404));
  }
  error_log("Historial obtenido para uid: {$uid} y ga: {$ga}");
  return rest_ensure_response($resultados);
}


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
        <?php echo submit_button('Limpiar Tabla de Seguimiento', 'secondary', 'clear_tracking_table', false); ?>
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
          <td><input type="text" name="location_id" id="location_id" placeholder="ID secuencial" class="regular-text" disabled value="<?php echo $editing ? esc_attr($edit_record['location_id']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="url_ubicacion">URL Ubicación</label></th>
          <td><input type="text" name="url_ubicacion" id="url_ubicacion" placeholder="https://www.google.com" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['url_ubicacion']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="user_id">User ID</label></th>
          <td><input type="text" name="user_id" id="user_id" placeholder="" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['user_id']) : ''; ?>"></td>
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
          <td><input type="number" step="any" name="lat" id="lat" placeholder="-0.176281" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['lat']) : ''; ?>"></td>
        </tr>
        <tr>
          <th scope="row"><label for="lng">Longitud</label></th>
          <td><input type="number" step="any" name="lng" id="lng" placeholder="-78.485821" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['lng']) : ''; ?>"></td>
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
   * Crea la tabla de Parametros.
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
    error_log("Tabla $table_name creada/actualizada para Paarametros.");
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
      echo '<div class="error"><p>Error de seguridad en Parametros. Inténtalo de nuevo.</p></div>';
      return;
    }

    // Eliminar registro
    if (isset($_POST['delete_rrss']) && ! empty($_POST['rrss_record_id'])) {
      $record_id = intval($_POST['rrss_record_id']);
      $result    = $wpdb->delete($table_name, array('id' => $record_id), array('%d'));
      if ($result !== false) {
        echo '<div class="updated"><p>Parametro eliminado correctamente.</p></div>';
      } else {
        echo '<div class="error"><p>Error al eliminar la parametro.</p></div>';
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
        echo '<div class="updated"><p>Parametro actualizado correctamente.</p></div>';
      } else {
        echo '<div class="error"><p>Error al actualizar el parametro.</p></div>';
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
        echo '<div class="updated"><p>Parametro agregado correctamente.</p></div>';
      } else {
        echo '<div class="error"><p>Error al agregar parametro.</p></div>';
      }
    }

    // Resetear la tabla
    if (isset($_POST['reset_rrss_table'])) {
      $result = $wpdb->query("TRUNCATE TABLE $table_name");
      if ($result !== false) {
        wp_cache_flush();
        echo '<div class="updated"><p>La tabla de parametros ha sido vaciada correctamente.</p></div>';
      } else {
        echo '<div class="error"><p>Error al vaciar la tabla de parametros</p></div>';
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
      <h2><?php echo $editing ? "Editar Parametro" : "Agregar Parametro"; ?></h2>
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
              <th scope="row"><label for="valor">Valor (Parametro)</label></th>
              <td>
                <input type="url" name="valor" id="valor" placeholder="https://www.facebook.com/tuempresa" class="regular-text" required value="<?php echo $editing ? esc_attr($edit_record['valor']) : ''; ?>">
              </td>
            </tr>
          </table>
          <?php if ($editing) : ?>
            <?php submit_button('Actualizar Parametro', 'primary', 'update_rrss', false); ?>
            <a href="<?php echo admin_url('admin.php?page=alfa-business-p'); ?>" class="button">Cancelar Edición</a>
          <?php else : ?>
            <?php submit_button('Agregar Parametro', 'primary', 'alfa_business_add_rrss', false); ?>
          <?php endif; ?>
      </form>
      <hr>
      <h2>Listado de Redes Sociales</h2>
      <form method="post" action="">
        <?php wp_nonce_field('alfa_business_save_rrss', 'alfa_business_rrss_nonce'); ?>
        <?php submit_button('Resetear Tabla de Parametros', 'secondary', 'reset_rrss_table', false); ?>
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
              <th>Valor (Parametro)</th>
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
                    <input type="submit" name="delete_rrss" class="button" value="Eliminar" onclick="return confirm('¿Seguro que deseas eliminar este parametro?');">
                  </form>
                  <a href="<?php echo admin_url('admin.php?page=alfa-business-p&action=edit_rrss&record_id=' . intval($item['id'])); ?>" class="button">Editar</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php
      } else {
        echo '<p>No se han registrado parametros.</p>';
      }
      ?>
    </div>
  <?php
  }
  ?>