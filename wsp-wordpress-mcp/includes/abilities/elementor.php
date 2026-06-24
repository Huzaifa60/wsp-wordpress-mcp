<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// ─────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────

function wsp_elementor_is_active() {
    return class_exists( '\Elementor\Plugin' );
}

function wsp_elementor_get_data( $post_id ) {
    if ( 'builder' !== get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
        return new WP_Error( 'not_elementor_page', 'This post was not built with Elementor.' );
    }
    $raw  = get_post_meta( $post_id, '_elementor_data', true );
    $data = $raw ? json_decode( $raw, true ) : array();
    return is_array( $data ) ? $data : array();
}

function wsp_elementor_save_data( $post_id, $data ) {
    update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
    update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
    if ( wsp_elementor_is_active() && isset( \Elementor\Plugin::$instance->files_manager ) ) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
}

function wsp_elementor_generate_id() {
    return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
}

// Recursively find an element by ID.
function wsp_elementor_find_by_id( $elements, $id ) {
    foreach ( $elements as $el ) {
        if ( $el['id'] === $id ) return $el;
        if ( ! empty( $el['elements'] ) ) {
            $found = wsp_elementor_find_by_id( $el['elements'], $id );
            if ( null !== $found ) return $found;
        }
    }
    return null;
}

// Recursively remove an element by ID.
function wsp_elementor_remove_by_id( &$elements, $id ) {
    foreach ( $elements as $i => $el ) {
        if ( $el['id'] === $id ) {
            array_splice( $elements, $i, 1 );
            return true;
        }
        if ( ! empty( $elements[ $i ]['elements'] ) ) {
            if ( wsp_elementor_remove_by_id( $elements[ $i ]['elements'], $id ) ) return true;
        }
    }
    return false;
}

// Recursively merge settings into an element by ID.
function wsp_elementor_update_by_id( &$elements, $id, $settings ) {
    foreach ( $elements as &$el ) {
        if ( $el['id'] === $id ) {
            $el['settings'] = array_merge( isset( $el['settings'] ) ? $el['settings'] : array(), $settings );
            return true;
        }
        if ( ! empty( $el['elements'] ) ) {
            if ( wsp_elementor_update_by_id( $el['elements'], $id, $settings ) ) return true;
        }
    }
    return false;
}

// Insert a new element into a specific parent by ID.
function wsp_elementor_insert_into( &$elements, $parent_id, $new_element, $position ) {
    foreach ( $elements as &$el ) {
        if ( $el['id'] === $parent_id ) {
            if ( null !== $position ) {
                array_splice( $el['elements'], $position, 0, array( $new_element ) );
            } else {
                $el['elements'][] = $new_element;
            }
            return true;
        }
        if ( ! empty( $el['elements'] ) ) {
            if ( wsp_elementor_insert_into( $el['elements'], $parent_id, $new_element, $position ) ) return true;
        }
    }
    return false;
}

// Find the ID of the first insertable element (container or column).
function wsp_elementor_first_insertable( $elements ) {
    foreach ( $elements as $el ) {
        $type = isset( $el['elType'] ) ? $el['elType'] : '';
        if ( in_array( $type, array( 'container', 'column' ), true ) ) return $el['id'];
        if ( ! empty( $el['elements'] ) ) {
            $found = wsp_elementor_first_insertable( $el['elements'] );
            if ( $found ) return $found;
        }
    }
    return null;
}

// Build a simplified tree for get-page response.
function wsp_elementor_simplify_tree( $elements ) {
    $result = array();
    foreach ( $elements as $el ) {
        $item = array(
            'id'   => $el['id'],
            'type' => isset( $el['elType'] ) ? $el['elType'] : 'unknown',
        );
        if ( ! empty( $el['widgetType'] ) ) {
            $item['widget_type'] = $el['widgetType'];
        }
        // Surface the first recognisable text setting as a preview.
        $s = isset( $el['settings'] ) ? $el['settings'] : array();
        foreach ( array( 'title', 'text', 'editor', 'html', 'url' ) as $k ) {
            if ( ! empty( $s[ $k ] ) && is_string( $s[ $k ] ) ) {
                $item['preview'] = wp_trim_words( wp_strip_all_tags( $s[ $k ] ), 8 );
                break;
            }
        }
        if ( ! empty( $el['elements'] ) ) {
            $item['children'] = wsp_elementor_simplify_tree( $el['elements'] );
        }
        $result[] = $item;
    }
    return $result;
}

// Collect matching elements into $results.
function wsp_elementor_search_tree( $elements, $widget_type, $search, &$results ) {
    foreach ( $elements as $el ) {
        $type_match   = ! $widget_type || ( isset( $el['widgetType'] ) && $el['widgetType'] === $widget_type );
        $search_match = ! $search      || false !== stripos( wp_json_encode( isset( $el['settings'] ) ? $el['settings'] : array() ), $search );

        if ( $type_match && $search_match && ( $widget_type || $search ) ) {
            $results[] = array(
                'id'          => $el['id'],
                'type'        => isset( $el['elType'] ) ? $el['elType'] : 'unknown',
                'widget_type' => isset( $el['widgetType'] ) ? $el['widgetType'] : null,
            );
        }
        if ( ! empty( $el['elements'] ) ) {
            wsp_elementor_search_tree( $el['elements'], $widget_type, $search, $results );
        }
    }
}

// ─────────────────────────────────────────────
// EXECUTE CALLBACKS
// ─────────────────────────────────────────────

function wsp_execute_elementor_list_pages( $input ) {
    $post_type = isset( $input['post_type'] ) ? sanitize_text_field( wp_unslash( $input['post_type'] ) ) : 'page';
    $status    = isset( $input['status'] )    ? sanitize_text_field( wp_unslash( $input['status'] ) )    : 'publish';
    $per_page  = isset( $input['per_page'] )  ? intval( $input['per_page'] ) : 20;

    $q = new WP_Query( array(
        'post_type'      => $post_type,
        'post_status'    => $status,
        'posts_per_page' => $per_page,
        'meta_key'       => '_elementor_edit_mode',
        'meta_value'     => 'builder',
    ) );

    $pages = array();
    foreach ( $q->posts as $post ) {
        $pages[] = array(
            'id'     => $post->ID,
            'title'  => $post->post_title,
            'url'    => get_permalink( $post->ID ),
            'status' => $post->post_status,
            'type'   => $post->post_type,
        );
    }
    return array( 'pages' => $pages, 'total' => $q->found_posts );
}

function wsp_execute_elementor_get_page( $input ) {
    $post_id = intval( $input['post_id'] );
    $data    = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );
    return array( 'post_id' => $post_id, 'structure' => wsp_elementor_simplify_tree( $data ) );
}

function wsp_execute_elementor_get_element( $input ) {
    $post_id    = intval( $input['post_id'] );
    $element_id = sanitize_text_field( wp_unslash( $input['element_id'] ) );

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    $element = wsp_elementor_find_by_id( $data, $element_id );
    if ( ! $element ) return array( 'success' => false, 'error' => 'Element not found.' );

    return array(
        'id'          => $element['id'],
        'type'        => isset( $element['elType'] )    ? $element['elType']    : 'unknown',
        'widget_type' => isset( $element['widgetType'] ) ? $element['widgetType'] : null,
        'settings'    => isset( $element['settings'] )  ? $element['settings']  : array(),
    );
}

function wsp_execute_elementor_find_element( $input ) {
    $post_id     = intval( $input['post_id'] );
    $widget_type = isset( $input['widget_type'] ) ? sanitize_text_field( wp_unslash( $input['widget_type'] ) ) : '';
    $search      = isset( $input['search'] )      ? sanitize_text_field( wp_unslash( $input['search'] ) )      : '';

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    $results = array();
    wsp_elementor_search_tree( $data, $widget_type, $search, $results );
    return array( 'results' => $results, 'total' => count( $results ) );
}

function wsp_execute_elementor_list_templates( $input ) {
    $per_page = isset( $input['per_page'] ) ? intval( $input['per_page'] ) : 20;
    $args     = array(
        'post_type'      => 'elementor_library',
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
    );
    if ( ! empty( $input['type'] ) ) {
        $args['tax_query'] = array( array(
            'taxonomy' => 'elementor_library_type',
            'field'    => 'slug',
            'terms'    => sanitize_text_field( wp_unslash( $input['type'] ) ),
        ) );
    }
    $q         = new WP_Query( $args );
    $templates = array();
    foreach ( $q->posts as $post ) {
        $templates[] = array(
            'id'    => $post->ID,
            'title' => $post->post_title,
            'type'  => get_post_meta( $post->ID, '_elementor_template_type', true ),
            'date'  => get_the_date( 'Y-m-d', $post->ID ),
        );
    }
    return array( 'templates' => $templates, 'total' => $q->found_posts );
}

function wsp_execute_elementor_update_element( $input ) {
    $post_id    = intval( $input['post_id'] );
    $element_id = sanitize_text_field( wp_unslash( $input['element_id'] ) );
    $settings   = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? $input['settings'] : array();

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    if ( ! wsp_elementor_update_by_id( $data, $element_id, $settings ) ) {
        return array( 'success' => false, 'error' => 'Element not found.' );
    }

    wsp_elementor_save_data( $post_id, $data );
    return array( 'success' => true, 'post_id' => $post_id, 'element_id' => $element_id );
}

function wsp_execute_elementor_add_widget( $input ) {
    $post_id      = intval( $input['post_id'] );
    $widget_type  = sanitize_text_field( wp_unslash( $input['widget_type'] ) );
    $container_id = isset( $input['container_id'] ) ? sanitize_text_field( wp_unslash( $input['container_id'] ) ) : null;
    $settings     = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? $input['settings'] : array();
    $position     = isset( $input['position'] ) ? intval( $input['position'] ) : null;

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    $new_widget = array(
        'id'         => wsp_elementor_generate_id(),
        'elType'     => 'widget',
        'widgetType' => $widget_type,
        'settings'   => $settings,
        'elements'   => array(),
    );

    if ( $container_id ) {
        $target = wsp_elementor_find_by_id( $data, $container_id );
        if ( ! $target ) return array( 'success' => false, 'error' => 'Container not found.' );

        // Widgets cannot go directly inside a legacy section — they need a column.
        if ( isset( $target['elType'] ) && 'section' === $target['elType'] ) {
            return array( 'success' => false, 'error' => 'Target is a section (legacy layout). Please target a column inside the section instead.' );
        }

        if ( ! wsp_elementor_insert_into( $data, $container_id, $new_widget, $position ) ) {
            return array( 'success' => false, 'error' => 'Failed to insert widget.' );
        }
    } else {
        $auto_parent = wsp_elementor_first_insertable( $data );
        if ( ! $auto_parent ) {
            return array( 'success' => false, 'error' => 'No container or column found. Create one first or provide container_id.' );
        }
        if ( ! wsp_elementor_insert_into( $data, $auto_parent, $new_widget, null ) ) {
            return array( 'success' => false, 'error' => 'Failed to insert widget.' );
        }
    }

    wsp_elementor_save_data( $post_id, $data );
    return array( 'success' => true, 'element_id' => $new_widget['id'], 'widget_type' => $widget_type );
}

function wsp_execute_elementor_add_container( $input ) {
    $post_id   = intval( $input['post_id'] );
    $type      = isset( $input['type'] ) ? sanitize_text_field( wp_unslash( $input['type'] ) ) : 'container';
    $parent_id = isset( $input['parent_id'] ) ? sanitize_text_field( wp_unslash( $input['parent_id'] ) ) : null;
    $settings  = ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) ? $input['settings'] : array();
    $position  = isset( $input['position'] ) ? intval( $input['position'] ) : null;

    // Normalise type — only container (modern) and section (legacy) are valid root types.
    if ( ! in_array( $type, array( 'container', 'section' ), true ) ) {
        $type = 'container';
    }

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    $new_element = array(
        'id'       => wsp_elementor_generate_id(),
        'elType'   => $type,
        'settings' => $settings,
        'elements' => array(),
        'isInner'  => false,
    );

    if ( $parent_id ) {
        if ( ! wsp_elementor_insert_into( $data, $parent_id, $new_element, $position ) ) {
            return array( 'success' => false, 'error' => 'Parent element not found.' );
        }
    } else {
        if ( null !== $position ) {
            array_splice( $data, $position, 0, array( $new_element ) );
        } else {
            $data[] = $new_element;
        }
    }

    wsp_elementor_save_data( $post_id, $data );
    return array( 'success' => true, 'element_id' => $new_element['id'], 'type' => $type );
}

function wsp_execute_elementor_remove_element( $input ) {
    $post_id    = intval( $input['post_id'] );
    $element_id = sanitize_text_field( wp_unslash( $input['element_id'] ) );

    $data = wsp_elementor_get_data( $post_id );
    if ( is_wp_error( $data ) ) return array( 'success' => false, 'error' => $data->get_error_message() );

    if ( ! wsp_elementor_remove_by_id( $data, $element_id ) ) {
        return array( 'success' => false, 'error' => 'Element not found.' );
    }

    wsp_elementor_save_data( $post_id, $data );
    return array( 'success' => true, 'message' => "Element {$element_id} removed." );
}
