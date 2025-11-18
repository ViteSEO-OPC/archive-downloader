<?php
/**
 * Example Carousel — server render (Bootstrap 5)
 */
if (!defined('ABSPATH')) { exit; }

/** Polyfill for PHP < 8.1 */
if (!function_exists('array_is_list')) {
  function array_is_list($array) {
    if (!is_array($array)) return false;
    $i = 0; foreach ($array as $k => $_) { if ($k !== $i++) return false; }
    return true;
  }
}

/* 1) Start with attrs */
$A = (isset($attributes) && is_array($attributes)) ? $attributes : [];

/* 2) Is block “blank”? */
$hasTitle  = trim($A['title'] ?? '') !== '';
$hasIntro  = trim($A['intro'] ?? '') !== '';
$hasItems  = !empty($A['items']) && is_array($A['items']);
$needsData = !($hasTitle || $hasIntro || $hasItems);

/* 3) Load region JSON if blank */
if ($needsData) {
  if (!function_exists('child_load_region_data')) {
    require_once trailingslashit(get_stylesheet_directory()) . 'inc/region-data.php';
  }
  $data = child_load_region_data();

  $key   = isset($A['dataKey']) ? (string)$A['dataKey'] : 'exampleCarousels';
  $index = isset($A['dataIndex']) ? (int)$A['dataIndex'] : 0;

  $loaded = null;
  $fallbackKeys = array_unique(array_filter([
    $key,
    'exampleCarousels',
    'carousels',
    'carousel',
  ]));

  foreach ($fallbackKeys as $candidateKey) {
    if (empty($data[$candidateKey]) || !is_array($data[$candidateKey])) {
      continue;
    }

    $pool = $data[$candidateKey];
    if (array_is_list($pool) && isset($pool[$index]) && is_array($pool[$index])) {
      $loaded = $pool[$index];
      break;
    }

    if (!array_is_list($pool)) {
      $loaded = $pool;
      break;
    }
  }

  if ($loaded) {
    $A = array_merge([
      "title"            => "",
      "intro"            => "",
      "backgroundType"   => "none",
      "backgroundValue"  => "",
      "cols"             => ["xs"=>1,"sm"=>2,"md"=>3,"lg"=>3,"xl"=>5],
      "cardLinkBehavior" => "stretched",
      "autoplay"         => false,
      "interval"         => 5000,
      "pauseOnHover"     => false,
      "wrap"             => true,
      "showControls"     => false,
      "showIndicators"   => false,
      "items"            => []
    ], $loaded);
  }
}

/* 4) Items (optional WP query) */
$items = (isset($A['items']) && is_array($A['items'])) ? $A['items'] : [];

if (empty($items) && !empty($A['source']) && is_array($A['source'])) {
  $src = $A['source'];

    // Normalize post types: string or array → array
  $postTypeRaw = $src['postType'] ?? ['post'];
  $postTypes   = is_array($postTypeRaw)
    ? array_values(array_filter(array_map('strval', $postTypeRaw)))
    : [ (string) $postTypeRaw ];
  if (!$postTypes) { $postTypes = ['post']; }

  $taxonomy  = isset($src['taxonomy']) ? (string)$src['taxonomy'] : 'category';
  $terms     = !empty($src['terms']) ? array_map('sanitize_title', (array)$src['terms']) : [];
  $perPage   = max(1, (int)($src['perPage'] ?? 10));
  $orderby   = $src['orderby'] ?? 'date';
  $order     = strtoupper($src['order'] ?? 'DESC');
  $offset    = (int)($src['offset'] ?? 0);
  $includeCh = !empty($src['includeChildren']);
  $thumbSize = $src['thumbSize'] ?? 'large';

  // Guard order/orderby to sane values
  $allowed_orderby = ['date','title','menu_order','modified','rand','ID','author'];
  if (!in_array($orderby, $allowed_orderby, true)) { $orderby = 'date'; }
  $order = ($order === 'ASC') ? 'ASC' : 'DESC';

  $searchTerm = '';
  if (!empty($src['searchFromUrl'])) {
    $searchTerm = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
  }
  if ($searchTerm === '' && !empty($src['search'])) $searchTerm = (string)$src['search'];

  $tags = !empty($src['tags']) ? array_filter(array_map('sanitize_title', (array)$src['tags'])) : [];

  $args = [
    'post_type'           => $postTypes,            // ← array supported
    'posts_per_page'      => $perPage,
    'orderby'             => $orderby,
    'order'               => $order,
    'offset'              => $offset,
    'post_status'         => 'publish',
    'no_found_rows'       => true,
    'ignore_sticky_posts' => true,
  ];
  if ($searchTerm !== '') $args['s'] = $searchTerm;

  // Build tax_query cleanly
  $tax_query = [];
  if ($taxonomy && !empty($terms)) {
    $tax_query[] = [
      'taxonomy'         => $taxonomy,
      'field'            => 'slug',
      'terms'            => $terms,
      'include_children' => $includeCh,
      'operator'         => 'IN',
    ];
  }
  if (!empty($tags)) {
    $tax_query[] = [
      'taxonomy' => 'post_tag',
      'field'    => 'slug',
      'terms'    => $tags,
      'operator' => 'IN',
    ];
  }
  if ($tax_query) {
    $args['tax_query'] = (count($tax_query) > 1)
      ? array_merge(['relation' => 'AND'], $tax_query)
      : $tax_query;
  }


  $q = new WP_Query($args);
  if ($q->have_posts()) {
    while ($q->have_posts()) {
      $q->the_post();
      $pid      = get_the_ID();
      $imgUrl   = get_the_post_thumbnail_url($pid, $thumbSize);
      if (!$imgUrl) $imgUrl = 'http://allinclusive.local/wp-content/uploads/2025/09/404.jpg';

      $alt = '';
      $thumb_id = get_post_thumbnail_id($pid);
      if ($thumb_id) {
        $alt = get_post_meta($thumb_id, '_wp_attachment_image_alt', true) ?: get_the_title($pid);
      }

      $tagText = '';
      if ($taxonomy) {
        $t = get_the_terms($pid, $taxonomy);
        if ($t && !is_wp_error($t)) {
          foreach ($t as $term_obj) {
            if (in_array($term_obj->slug, $terms, true)) { $tagText = $term_obj->name; break; }
          }
          if ($tagText === '') { $tagText = $t[0]->name; }
        }
      }

      $items[] = [
        'imageURL'    => esc_url($imgUrl),
        'imageAlt'    => esc_attr($alt),
        'heading'     => html_entity_decode(get_the_title()),
        'text'        => wp_strip_all_tags(get_the_excerpt()),
        'buttonLabel' => 'Read article',
        'buttonURL'   => get_permalink($pid),
        'tag'         => $tagText,
        'meta'        => 'By ' . get_the_author(),
        'date'        => get_the_date(),
        'imageOnly'   => false,
      ];
    }
    wp_reset_postdata();
  }
}
if (empty($items)) return;

/* Shared bits */
$title  = trim($A['title'] ?? '');
$intro  = trim($A['intro'] ?? '');
$cols   = $A['cols'] ?? ['xs'=>1,'sm'=>2,'md'=>3,'lg'=>4,'xl'=>5];
$bps    = ['xs','sm','md','lg','xl'];

/** Build row-cols classes */
$rowColsPieces = [];
foreach ($bps as $bp) {
  if (!empty($cols[$bp])) {
    $n = max(1, (int)$cols[$bp]);
    $rowColsPieces[] = ($bp === 'xs') ? "row-cols-$n" : "row-cols-$bp-$n";
  }
}
$rowColsClasses = 'row ' . implode(' ', $rowColsPieces) . ' g-3 g-md-4';

/** Flags */
$cardLinkBehavior = $A['cardLinkBehavior'] ?? 'stretched';
$autoplay         = !empty($A['autoplay']);
$interval         = (int)($A['interval'] ?? 5000);
$pauseOnHover     = !empty($A['pauseOnHover']);
$wrap             = !empty($A['wrap']);
$showControls     = !empty($A['showControls']);
$showIndicators   = !empty($A['showIndicators']);

$isCarousel = ($autoplay || $showControls || $showIndicators);

$ariaLabel = $A['ariaLabel'] ?? 'Example Carousel';
$id        = !empty($A['instanceId']) ? $A['instanceId'] : ('example_carousel_' . uniqid());

/** Background */
$bgType  = $A['backgroundType'] ?? 'none';
$bgValue = trim($A['backgroundValue'] ?? '');
$bgStyle = ($bgType !== 'none' && $bgValue !== '') ? 'background:' . $bgValue . ';' : '';

/** Align controls */
$map_align      = ['left'=>'text-start','center'=>'text-center','right'=>'text-end'];
$headAlignClass = $map_align[$A['textPositionHeading'] ?? 'center'] ?? 'text-center';
$textAlignClass = $map_align[$A['textPositionText'] ?? 'left'] ?? 'text-start';

/** Card renderer */
$print_card = function(array $card) use ($cardLinkBehavior, $headAlignClass, $textAlignClass) {
  $img   = esc_url($card['imageURL'] ?? '');
  $alt   = esc_attr($card['imageAlt'] ?? '');
  $h     = trim($card['heading'] ?? '');
  $txt   = trim($card['text'] ?? '');
  $label = trim($card['buttonLabel'] ?? '');
  $url   = esc_url($card['buttonURL'] ?? '');
  $meta  = trim($card['meta'] ?? '');
  $date  = trim($card['date'] ?? '');
  $isImageOnly = !empty($card['imageOnly']);
  $buttonText  = $label !== '' ? $label : 'Learn More';
  $ratio = $isImageOnly ? '133.333%' : '56.25%';

  // Link behavior
  $hasUrl    = $url !== '';
  $hasButton = $hasUrl && ($label !== '');
  if ($cardLinkBehavior === 'none') {
    $renderButton = false; $renderStretched = false;
  } elseif ($cardLinkBehavior === 'button-only') {
    $renderButton = $hasUrl; $renderStretched = false;
  } else { // 'stretched'
    $renderButton = $hasButton; $renderStretched = $hasUrl && !$hasButton;
  } 
  ?>
  <div class="col">
    <article class="card <?php echo $isImageOnly ? '' : 'h-100'; ?> shadow-sm example-carousel-card <?php echo $isImageOnly ? 'image-only' : 'has-body'; ?>">

      <?php if ($img): ?>
        <div class="ratio card-media" style="--bs-aspect-ratio: <?php echo esc_attr($ratio); ?>;">
          <img src="<?php echo $img; ?>" alt="<?php echo $alt; ?>" class="card-img-top object-cover">
          <?php if ($isImageOnly && $h !== ''): ?>
            <div class="tile-overlay">
              <div class="tile-title"><?php echo esc_html($h); ?></div>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if (!$isImageOnly): ?>
        <div class="card-body">
            <?php if ($h !== ''): ?>
              <h3 class="card-title mb-2 <?php echo esc_attr($headAlignClass); ?>"><?php echo esc_html($h); ?></h3>
            <?php endif; ?>

            <?php if ($txt !== ''):
              $lines = preg_split('/\r\n|\r|\n/', $txt);
              $isList = count($lines) > 1 || preg_match('/^\s*\*/', $lines[0] ?? '');
            ?>
              <?php if ($isList): ?>
                <ul class="card-text mb-3 <?php echo esc_attr($textAlignClass); ?>">
                  <?php foreach ($lines as $line) {
                    $line = trim(ltrim($line, '* ')); if ($line === '') continue; ?>
                    <li><?php echo esc_html($line); ?></li>
                  <?php } ?>
                </ul>
              <?php else: ?>
                <p class="card-text mb-3 <?php echo esc_attr($textAlignClass); ?>"><?php echo esc_html($txt); ?></p>
              <?php endif; ?>
            <?php endif; ?>

            <!-- 👇 New: foot = meta + button; this whole group is pinned to bottom -->
            <div class="card-foot mt-auto">
              <?php if ($meta !== '' || $date !== ''): ?>
                <div class="card-meta border-top pt-2">
                  <?php if ($meta !== ''): ?><div class="meta-line"><?php echo esc_html($meta); ?></div><?php endif; ?>
                  <?php if ($date !== ''): ?><div class="meta-line"><?php echo esc_html($date); ?></div><?php endif; ?>
                </div>
              <?php endif; ?>

              <?php if ($renderButton): ?>
                <a class="btn btn-danger btn-sm w-100" href="<?php echo $url; ?>">
                  <?php echo esc_html($buttonText); ?>
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>
      <?php if ($renderStretched): ?>
        <a class="stretched-link" href="<?php echo $url; ?>" aria-label="<?php echo esc_attr($h ?: 'Open'); ?>"></a>
      <?php endif; ?>
    </article>
  </div>
  <?php
};

/* ---------- RENDER ---------- */
?>
<section class="example-carousel-block<?php echo $bgStyle ? ' has-bg' : ''; ?>"<?php echo $bgStyle ? ' style="'.esc_attr($bgStyle).'"' : ''; ?>>
  <?php if ($title || $intro): ?>
    <header class="example-carousel-head">
      <?php if ($title): ?><h2 class="example-carousel-title"><?php echo esc_html($title); ?></h2><?php endif; ?>
      <?php if ($intro): ?><p class="example-carousel-intro"><?php echo esc_html($intro); ?></p><?php endif; ?>
    </header>
  <?php endif; ?>

  <?php if (!$isCarousel): ?>
    <!-- GRID MODE (unchanged) -->
    <div class="container-fluid p-0 example-carousel-grid">
      <div class="<?php echo esc_attr($rowColsClasses); ?>">
        <?php foreach ($items as $card) { $print_card($card); } ?>
      </div>
    </div>

  <?php else: ?>
    <?php
      /* IDs for mobile indicators */
      $scope_id  = 'pscope_' . $id;
      $slide_ids = [];
      foreach ($items as $idx => $_) { $slide_ids[] = $id . '-s' . $idx; }
    ?>

    <!-- ===== MOBILE/TABLET SLIDER (peeked view) ===== -->
    <div id="<?php echo esc_attr($scope_id); ?>" class="peek-slider is-mobile" aria-roledescription="carousel" aria-label="<?php echo esc_attr($ariaLabel); ?>">
      <div class="peek-track">
        <?php foreach ($items as $i => $card): ?>
          <div class="peek-slide example-carousel-slide" id="<?php echo esc_attr($slide_ids[$i]); ?>">
            <div class="row row-cols-1 g-3">
              <?php $print_card($card); ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Mobile/tablet radio dots -->
      <nav class="peek-indicators" aria-label="Example carousel indicators">
        <?php foreach ($items as $i => $_): ?>
          <a class="pi-dot<?php echo $i === 0 ? ' is-active' : ''; ?>" data-i="<?php echo (int)$i; ?>"
             href="#<?php echo esc_attr($slide_ids[$i]); ?>"
             aria-label="<?php printf('Go to slide %d', $i + 1); ?>"></a>
        <?php endforeach; ?>
      </nav>

      <!-- JS: keep dots in sync with swipe & prevent hash jumps -->
      <script>
        (function(){
          const scope  = document.getElementById('<?php echo esc_js($scope_id); ?>');
          if(!scope || scope.dataset.bound === '1') return; // avoid duplicates
          scope.dataset.bound = '1';

          const container = scope; // .peek-slider
          const track     = scope.querySelector('.peek-track');
          const slides    = Array.from(scope.querySelectorAll('.peek-slide'));
          const dots      = Array.from(scope.querySelectorAll('.pi-dot'));

          // 1) Clicking a dot -> smooth scroll that slide into view
          dots.forEach((dot,i)=>{
            dot.addEventListener('click', function(e){
              e.preventDefault();
              slides[i].scrollIntoView({behavior:'smooth', inline:'center', block:'nearest'});
              setActive(i);
            }, {passive:false});
          });

          // 2) While user swipes, update active dot to the slide closest to center
          const setActiveByCenter = () => {
            const cRect = container.getBoundingClientRect();
            const cMid  = cRect.left + cRect.width/2;
            let bestI = 0, bestDist = Infinity;
            slides.forEach((sl, i) => {
              const r = sl.getBoundingClientRect();
              const mid = r.left + r.width/2;
              const d = Math.abs(mid - cMid);
              if (d < bestDist) { bestDist = d; bestI = i; }
            });
            setActive(bestI);
          };

          const setActive = (idx) => {
            dots.forEach(d => d.classList.remove('is-active'));
            if (dots[idx]) dots[idx].classList.add('is-active');
          };

          let ticking = false;
          const onScroll = () => {
            if (!ticking) {
              window.requestAnimationFrame(() => { setActiveByCenter(); ticking = false; });
              ticking = true;
            }
          };

          container.addEventListener('scroll', onScroll, {passive:true});
          window.addEventListener('resize', setActiveByCenter);
          setActiveByCenter();
        })();
      </script>
    </div>

    <!-- ===== DESKTOP BOOTSTRAP CAROUSEL (unchanged) ===== -->
    <?php
      $largest  = 1;
      foreach ($bps as $bp) { if (!empty($cols[$bp])) $largest = max($largest, (int)$cols[$bp]); }
      $perSlide = max(1, $largest);
      $slides   = array_chunk($items, $perSlide);

      $data = [
        'data-bs-ride'     => $autoplay ? 'carousel' : false,
        'data-bs-interval' => $autoplay ? $interval : false,
        'data-bs-pause'    => $pauseOnHover ? 'hover' : 'false',
        'data-bs-wrap'     => $wrap ? 'true' : 'false',
        'data-bs-touch'    => 'true'
      ];
      $carouselDataAttr = '';
      foreach ($data as $k => $v) {
        if ($v !== false && $v !== null && $v !== '') {
          $carouselDataAttr .= sprintf(' %s="%s"', esc_attr($k), esc_attr($v));
        }
      }
    ?>
    <div id="<?php echo esc_attr($id); ?>"
         class="carousel slide example-carousel-stage is-desktop"
         aria-label="<?php echo esc_attr($ariaLabel); ?>"
         data-cols-xs="<?php echo (int)($cols['xs'] ?? 1); ?>"
         data-cols-sm="<?php echo (int)($cols['sm'] ?? 2); ?>"
         data-cols-md="<?php echo (int)($cols['md'] ?? 3); ?>"
         data-cols-lg="<?php echo (int)($cols['lg'] ?? 4); ?>"
         data-cols-xl="<?php echo (int)($cols['xl'] ?? 5); ?>"
         <?php echo $carouselDataAttr; ?>>

      <?php if ($showIndicators && count($slides) > 1): ?>
        <div class="carousel-indicators">
          <?php foreach ($slides as $i => $_): ?>
            <button type="button"
              data-bs-target="#<?php echo esc_attr($id); ?>"
              data-bs-slide-to="<?php echo esc_attr($i); ?>"
              <?php echo $i === 0 ? 'class="active" aria-current="true"' : ''; ?>
              aria-label="<?php printf('Slide %d', $i + 1); ?>"></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="carousel-inner">
        <?php foreach ($slides as $sIndex => $group): ?>
          <?php
            $isPartial = count($group) < $perSlide;
            $rowClassThisSlide = $rowColsClasses . ($isPartial ? ' justify-content-center' : '');
          ?>
          <div class="carousel-item <?php echo $sIndex === 0 ? 'active' : ''; ?>">
            <div class="<?php echo esc_attr($rowClassThisSlide); ?>">
              <?php foreach ($group as $card) { $print_card($card); } ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($showControls && count($slides) > 1): ?>
        <button class="carousel-control-prev" type="button" data-bs-target="#<?php echo esc_attr($id); ?>" data-bs-slide="prev" aria-label="Previous">
          <span class="slider-prev" aria-hidden="true">&#10094;</span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#<?php echo esc_attr($id); ?>" data-bs-slide="next" aria-label="Next">
          <span class="slider-next" aria-hidden="true">&#10095;</span>
        </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>
