<?php
/**
 * TablePress Rendering Class
 *
 * @package TablePress
 * @subpackage Rendering
 * @author Tobias Bäthge
 * @since 1.0.0
 */

// Prohibit direct script loading
defined( 'ABSPATH' ) || die( 'No direct script access allowed!' );

/**
 * TablePress Rendering Class
 * @package TablePress
 * @subpackage Rendering
 * @author Tobias Bäthge
 * @since 1.0.0
 */
class TablePress_Render {

	/**
	 * Table data that is rendered
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $table;

	/**
	 * Table options that influence the output result
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $render_options = array();

	/**
	 * Rendered HTML of the table
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	protected $output;

	/**
	 * Instance of EvalMath class
	 *
	 * @since 1.0.0
	 *
	 * @var object
	 */
	protected $evalmath;

	/**
	 * Trigger words for colspan, rowspan, or the combination of both
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $span_trigger = array(
		'colspan' => '#colspan#',
		'rowspan' => '#rowspan#',
		'span' => '#span#'
	);

	/**
	 * Buffer to store the counts of rowspan per column, initialized in _render_table()
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $rowspan = array();

	/**
	 * Buffer to store the counts of colspan per row, initialized in _render_table()
	 *
	 * @since 1.0.0
	 *
	 * @var array
	 */
	protected $colspan = array();

	/**
	 * Index of the last row of the visible data in the table, set in _render_table()
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	protected $last_row_idx;

	/**
	 * Index of the last column of the visible data in the table, set in _render_table()
	 *
	 * @since 1.0.0
	 *
	 * @var int
	 */
	protected $last_column_idx;
	
	/**
	 * Initialize the Rendering class, include the EvalMath class
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->evalmath = TablePress::load_class( 'EvalMath', 'evalmath.class.php', 'libraries', true ); // true for some default constants
		$this->evalmath->suppress_errors = true; // don't raise PHP warnings
	}

	/**
	 * Set the table (data, options, visibility, ...) that is to be rendered
	 *
	 * @since 1.0.0
	 *
	 * @param array $table Table to be rendered
	 * @param array $render_options Options for rendering, from both "Edit" screen and Shortcode
	 */
	public function set_input( $table, $render_options ) {
		$this->table = $table;
		$this->render_options = $render_options;
	}

	/**
	 * Process the table rendering and return the HTML output
	 *
	 * @since 1.0.0
	 *
	 * @return string HTML of the rendered table (or an error message)
	 */
	public function get_output() {
		// evaluate math expressions/formulas
		$this->_evaluate_table_data();
		// remove hidden rows and columns
		$this->_prepare_render_data();
		// generate HTML output
		$this->_render_table();
		return $this->output;
	}

	/**
	 * Remove all cells from the data set that shall not be rendered, because they are hidden
	 *
	 * @since 1.0.0
	 */
	protected function _prepare_render_data() {
		$orig_table = $this->table;

		// @TODO: Can this be replaced by ranges (like 3-8) in show/hide_rows/columns?
		// if row_offset or row_count were given, we cut that part from the table and show just that
		$this->table['data'] = array_slice( $this->table['data'], $this->render_options['row_offset'] - 1, $this->render_options['row_count'] ); // -1 because we start from 1

		// load information about hidden rows and columns
		$hidden_rows = isset( $this->table['visibility']['rows'] ) ? array_keys( $this->table['visibility']['rows'], 0 ) : array(); // get indexes of hidden rows (array value of 0))
		$hidden_rows = array_merge( $hidden_rows, $this->render_options['hide_rows'] );
		$hidden_rows = array_diff( $hidden_rows, $this->render_options['show_rows'] );
		$hidden_columns = isset( $this->table['visibility']['columns'] ) ? array_keys( $this->table['visibility']['columns'], 0 ) : array(); // get indexes of hidden columns (array value of 0))
		$hidden_columns = array_merge( $hidden_columns, $this->render_options['hide_columns'] );
		$hidden_columns = array_merge( array_diff( $hidden_columns, $this->render_options['show_columns'] ) );

		// remove hidden rows and re-index
		foreach ( $hidden_rows as $row_idx ) {
			if ( isset( $this->table['data'][$row_idx] ) )
				unset( $this->table['data'][$row_idx] );
		}
		$this->table['data'] = array_merge( $this->table['data'] );
		// remove hidden columns and re-index
		foreach ( $this->table['data'] as $row_idx => $row ) {
			foreach ( $hidden_columns as $col_idx ) {
				if ( isset( $row[$col_idx] ) )
					unset( $row[$col_idx] );
			}
			$this->table['data'][$row_idx] = array_merge( $row );
		}
		
		$this->table = apply_filters( 'tablepress_table_render_data', $this->table, $orig_table, $this->render_options );
	}

	/**
	 * Loop through the table to evaluate math expressions/formulas
	 *
	 * @since 1.0.0
	 */
	protected function _evaluate_table_data() {
		$orig_table = $this->table;

		$rows = count( $this->table['data'] );
		$columns = count( $this->table['data'][0] );
		for ( $row_idx = 0; $row_idx < $rows; $row_idx++ ) {
			for ( $col_idx = 0; $col_idx < $columns; $col_idx++ ) {
				$this->table['data'][$row_idx][$col_idx] = $this->_evaluate_cell( $this->table['data'][$row_idx][$col_idx] );
			}
		}

		$this->table = apply_filters( 'tablepress_table_evaluate_data', $this->table, $orig_table, $this->render_options );
	}

	/**
	 * Parse and evaluate the content of a cell
	 *
	 * @since 1.0.0
	 *
	 * @param string $content Content of a cell
	 * @param array $parents List of cells that depend on this cell (to prevent circle references)
	 * @return string Result of the parsing/evaluation
	 */
	protected function _evaluate_cell( $content, $parents = array() ) {
		if ( ( '' == $content ) || ( '=' != $content[0] ) )
			return $content;

		$expression = substr( $content, 1 );

		if ( false !== strpos( $expression, '=' ) )
			return '!ERROR! Too many "="';

		if ( false !== strpos( $expression, '][' ) )
			return '!ERROR! Two cell references next to each other';

		$replaced_references = $replaced_ranges = array();

		// remove all whitespace characters
		$expression = preg_replace( '#\s#', '', $expression );

		// expand cell ranges (like [A3:A6]) to a list of single cells (like [A3],[A4],[A5],[A6])
		if ( preg_match_all( '#\[([a-z]+)([0-9]+):([a-z]+)([0-9]+)\]#i', $expression, $referenced_cell_ranges, PREG_SET_ORDER ) ) {
			foreach ( $referenced_cell_ranges as $cell_range ) {
				if ( in_array( $cell_range[0], $replaced_ranges ) )
					continue;

				$replaced_ranges[] = $cell_range[0];

				if ( isset( $this->known_ranges[ $cell_range[0] ] ) ) {
					$expression = str_replace( $cell_range[0], $this->known_ranges[ $cell_range[0] ], $expression );
					continue;
				}

				// no -1 necessary for this transformation, as we don't actually access the table
				$first_col = TablePress::letter_to_number( $cell_range[1] );
				$first_row = $cell_range[2];
				$last_col = TablePress::letter_to_number( $cell_range[3] );
				$last_row = $cell_range[4];

				$col_start = min( $first_col, $last_col );
				$col_end = max( $first_col, $last_col ) + 1; // +1 for loop below
				$row_start = min( $first_row, $last_row );
				$row_end = max( $first_row, $last_row ) + 1; // +1 for loop below

				$cell_list = array();
				for ( $col = $col_start; $col < $col_end; $col++ ) {
					for ( $row = $row_start; $row < $row_end; $row++ ) {
						$column = TablePress::number_to_letter( $col );
						$cell_list[] = "[{$column}{$row}]";
					}
				}
				$cell_list = implode( ',', $cell_list );

				$expression = str_replace( $cell_range[0], $cell_list, $expression );
				$this->known_ranges[ $cell_range[0] ] = $cell_list;
			}
		}

		// parse and evaluate single cell references (like [A3] or [XY312]), while prohibiting circle references
		if ( preg_match_all( '#\[([a-z]+)([0-9]+)\]#i', $expression, $referenced_cells, PREG_SET_ORDER ) ) {
			foreach ( $referenced_cells as $cell_reference ) {
				if ( in_array( $cell_reference[0], $parents ) )
					return '!ERROR! Circle Reference';

				if ( in_array( $cell_reference[0], $replaced_references ) )
					continue;

				$replaced_references[] = $cell_reference[0];

				$ref_col = TablePress::letter_to_number( $cell_reference[1] ) - 1;
				$ref_row = $cell_reference[2] - 1;

				if ( ! ( isset( $this->table['data'][$ref_row] ) && isset( $this->table['data'][$ref_row][$ref_col] ) ) )
					return '!ERROR! Non-Existing Cell';

				$ref_parents = $parents;
				$ref_parents[] = $cell_reference[0];

				$result = $this->table['data'][$ref_row][$ref_col] = $this->_evaluate_cell( $this->table['data'][$ref_row][$ref_col], $ref_parents );
				if ( false !== strpos( $result, '!ERROR!' ) )
					return $result;

				$expression = str_replace( $cell_reference[0], $result, $expression );
			}
		}

		return $this->_evaluate_math_expression( $expression );
	}

	/**
	 * Evaluate a math expression
	 *
	 * @param string $expression without leading = sign
	 * @return string Result of the evaluation
	 */
	protected function _evaluate_math_expression( $expression ) {
		// straight up evaluation, without parsing of variable or function assignments (which is why we only need one instance of the object)
		$result = $this->evalmath->pfx( $this->evalmath->nfx( $expression ) );
		if ( false === $result )
			return '!ERROR! ' . $this->evalmath->last_error;
		else
			return $result;
	}

	/**
	 * Generate the HTML output of the table
	 *
	 * @since 1.0.0
	 */
	protected function _render_table() {
		$num_rows = count( $this->table['data'] );
		$num_columns = ( $num_rows > 0 ) ? count( $this->table['data'][0] ) : 0;

		// check if there are rows and columns in the table (might not be the case after removing to hidden rows/columns!)
		if ( 0 === $num_rows || 0 === $num_columns ) {
			$this->output = sprintf( __( '<!-- The table with the ID %s is empty! -->', 'tablepress' ), $this->table['id'] ); // @TODO: Maybe use a more meaningful output here?
			return;
		}

		// counters for spans of rows and columns, init to 1 for each row and column (as that means no span)
		$this->rowspan = array_fill( 0, $num_columns, 1 );
		$this->colspan = array_fill( 0, $num_rows, 1 );

		// allow overwriting the colspan and rowspan trigger keywords, by table ID
		$this->span_trigger = apply_filters( 'tablepress_span_trigger_keywords', $this->span_trigger, $this->table['id'] );

		// make array $shortcode_atts['column_widths'] have $columns entries
		$this->render_options['column_widths'] = array_pad( $this->render_options['column_widths'], $num_columns, '' );

		$output = '';

		if ( 'no' != $this->render_options['print_name'] ) {
			$print_name_html_tag = apply_filters( 'tablepress_print_name_html_tag', 'h2', $this->table['id'] );
			$print_name_css_class = apply_filters( 'tablepress_print_name_css_class', "tablepress-table-name tablepress-table-name-id-{$this->table['id']}", $this->table['id'] );
			$print_name_html = "<{$print_name_html_tag} class=\"{$print_name_css_class}\">" . $this->safe_output( $this->table['name'] ) . "</{$print_name_html_tag}>\n";
		}
		if ( 'no' != $this->render_options['print_description'] ) {
			$print_description_html_tag = apply_filters( 'tablepress_print_description_html_tag', 'span', $this->table['id'] );
			$print_description_css_class = apply_filters( 'tablepress_print_description_css_class', "tablepress-table-description tablepress-table-description-id-{$this->table['id']}", $this->table['id'] );
			$print_description_html = "<{$print_description_html_tag} class=\"{$print_description_css_class}\">" . $this->safe_output( $this->table['description'] ) . "</{$print_description_html_tag}>\n";
		}

		if ( 'above' == $this->render_options['print_name'] )
			$output .= $print_name_html;
		if ( 'above' == $this->render_options['print_description'] )
			$output .= $print_description_html;

		$thead = '';
		$tfoot = '';
		$tbody = array();

		$this->last_row_idx = $num_rows - 1;
		$this->last_column_idx = $num_columns - 1;
		// loop through rows in reversed order, to search for rowspan trigger keyword
		for ( $row_idx = $this->last_row_idx; $row_idx >= 0; $row_idx-- )	 {
			// last row, need to check for footer (but only if at least two rows)
			if ( $this->last_row_idx == $row_idx && $this->render_options['table_foot'] && $num_rows > 1 ) {
				$tfoot = $this->_render_row( $row_idx, 'th' );
				continue;
			}
			// first row, need to check for head (but only if at least two rows)
			if ( 0 == $row_idx && $this->render_options['table_head'] && $num_rows > 1 ) {
				$thead = $this->_render_row( $row_idx, 'th' );
				continue;
			}
			// neither first nor last row (with respective head/foot enabled), so render as body row
			$tbody[] = $this->_render_row( $row_idx, 'td' );
		}

		// <caption> tag (possibly with "Edit" link)
		$caption = apply_filters( 'tablepress_print_caption_text', '', $this->table );
		$caption_style = $caption_class = '';
		if ( ! empty( $caption ) )
			$caption_class = apply_filters( 'tablepress_print_caption_class', "tablepress-table-caption tablepress-table-caption-id-{$this->table['id']}", $this->table['id'] );
		if ( ! empty( $this->render_options['edit_table_url'] ) ) {
			if ( ! empty( $caption ) )
				$caption .= '<br/>';
			$caption .= "<a href=\"{$this->render_options['edit_table_url']}\" title=\"" . __( 'Edit', 'default' ) . "\">" . __( 'Edit', 'default' ) . "</a>";
			$caption_style = ' style="caption-side:bottom;text-align:left;border:none;background:none;"';
		}
		if ( ! empty( $caption ) )
			$caption = "<caption{$caption_class}{$caption_style}>\n{$caption}</caption>\n";

		// <colgroup> tag
		$colgroup = '';
		if ( apply_filters( 'tablepress_print_colgroup_tag', false, $this->table['id'] ) ) {
			for ( $col_idx = 0; $col_idx < $columns; $col_idx++ ) {
				$attributes = ' class="colgroup-column-' . ( $col_idx + 1 ) . ' "';
				$attributes = apply_filters( 'tablepress_colgroup_tag_attributes', $attributes, $this->table['id'], $col_idx + 1 );
				$colgroup .= "\t<col{$attributes}/>\n";
			}
		}
		if ( ! empty( $colgroup ) )
			$colgroup = "<colgroup>\n{$colgroup}</colgroup>\n";

		// <thead>, <tfoot>, and <tbody> tags
		if ( ! empty( $thead ) )
			$thead = "<thead>\n{$thead}</thead>\n";
		if ( ! empty( $tfoot ) )
			$tfoot = "<tfoot>\n{$tfoot}</tfoot>\n";
		$tbody_class = ( $this->render_options['row_hover'] ) ? ' class="row-hover"' : '';
		$tbody = array_reverse( $tbody ); // because we looped through the rows in reverse order
		$tbody = "<tbody{$tbody_class}>\n" . implode( '', $tbody ) . "</tbody>\n";

		// <table> attributes
		$id = " id=\"{$this->render_options['html_id']}\"";
		// classes that will be added to <table class="...">, for CSS styling
		$css_classes = array( 'tablepress', "tablepress-id-{$this->table['id']}", $this->render_options['extra_css_classes'] );
		$css_classes = apply_filters( 'tablepress_table_css_classes', $css_classes, $this->table['id'] );
		$class = ( ! empty( $css_classes ) ) ? ' class="' . trim( implode( ' ', $css_classes ) ) . '"' : '';
		$summary = apply_filters( 'tablepress_print_summary_attr', '', $this->table );
		$summary = ( ! empty( $summary ) ) ? ' summary="' . esc_attr( $summary ) . '"' : '';
		$cellspacing = ( false !== $this->render_options['cellspacing'] ) ? " cellspacing=\"{$this->render_options['cellspacing']}\"" : '';
		$cellpadding = ( false !== $this->render_options['cellpadding'] ) ? " cellpadding=\"{$this->render_options['cellpadding']}\"" : '';
		$border = ( false !== $this->render_options['border'] ) ? " border=\"{$this->render_options['border']}\"" : '';

		$output .= "\n<table{$id}{$class}{$summary}{$cellspacing}{$cellpadding}{$border}>\n";
		$output .= $caption . $colgroup . $thead . $tfoot . $tbody;
		$output .= "</table>\n";

		// name/description below table (HTML already generated above)
		if ( 'below' == $this->render_options['print_name'] )
			$output .= $print_name_html;
		if ( 'below' == $this->render_options['print_description'] )
			$output .= $print_description_html;

		$this->output = apply_filters( 'tablepress_table_output', $output , $this->table, $this->render_options );
	}

	/**
	 * Generate the HTML of a row
	 *
	 * @since 1.0.0
	 *
	 * @param int $row_idx Index of the row to be rendered
	 * @param string $tag HTML tag to use for the cells (td or th)
	 * @return string HTML for the row
	 */
	protected function _render_row( $row_idx, $tag ) {
		$row_cells = array();
		// loop through cells in reversed order, to search for colspan or rowspan trigger words
		for ( $col_idx = $this->last_column_idx; $col_idx >= 0; $col_idx-- )	 {
			$cell_content = $this->table['data'][ $row_idx ][ $col_idx ];

			// print formulas that are escaped with '= (like in Excel) as text:
			if ( strlen( $cell_content ) > 2 && "'=" == substr( $cell_content, 0, 2 ) )
				$cell_content = substr( $cell_content, 1 );
			// @TODO: Maybe do this on the full HTML output, instead on each cell individually?
			// @TODO: Maybe move this to after the colspan/rowspan checks in the next block?
			$cell_content = do_shortcode( $this->safe_output( $cell_content ) );
			$cell_content = apply_filters( 'tablepress_cell_content', $cell_content, $this->table['id'], $row_idx + 1, $col_idx + 1 );

			if ( $this->span_trigger['rowspan'] == $cell_content ) { // there will be a rowspan
				// check for #rowspan# in first row, which doesn't make sense
				if ( ( $row_idx > 1 && $row_idx < $this->last_row_idx )
				|| ( 1 == $row_idx && ! $this->render_options['table_head'] ) // no rowspan into table_head
				|| ( $this->last_row_idx == $row_idx && ! $this->render_options['table_foot'] ) ) { // no rowspan out of table_foot
					$this->rowspan[ $col_idx ]++; // increase counter for rowspan in this column
					$this->colspan[ $row_idx ] = 1; // reset counter for colspan in this row, combined col- and rowspan might be happening
					continue;
				}
				// invalid rowspan, so we set cell content from #rowspan# to a space
				$cell_content = '&nbsp;';
			} elseif ( $this->span_trigger['colspan'] == $cell_content ) { // there will be a colspan
				// check for #colspan# in first column, which doesn't make sense
				if ( $col_idx > 1
				|| ( 1 == $col_idx && ! $this->render_options['first_column_th'] ) ) { // no colspan into first column head
					$this->colspan[ $row_idx ]++; // increase counter for colspan in this row
					$this->rowspan[ $col_idx ] = 1; // reset counter for rowspan in this column, combined col- and rowspan might be happening
					continue;
				}
				// invalid colspan, so we set cell content from #colspan# to a space
				$cell_content = '&nbsp;';
			} elseif ( $this->span_trigger['span'] == $cell_content ) { // there will be a combined col- and rowspan
				// check for #span# in first column or first or last row, which is not always possible
				if ( ( $row_idx > 1 && $row_idx < $this->last_row_idx && $col_idx > 1 )
				// we are in first, second, or last row or in the first or second column, so more checks are necessary
				|| ( ( 1 == $row_idx && ! $this->render_options['table_head'] ) // no rowspan into table_head
					&& ( 1 == $col_idx && ! $this->render_options['first_column_th'] ) ) // and no colspan into first column head
				|| ( ( $this->last_row_idx == $row_idx && ! $this->render_options['table_foot'] ) // no rowspan out of table_foot
				 	&& ( 1 == $col_idx && ! $this->render_options['first_column_th'] ) ) ) // and no colspan into first column head
					continue;
				// invalid span, so we set cell content from #span# to a space
				$cell_content = '&nbsp;';
			}

			$span_attr = '';
			$cell_class = 'column-' . ( $col_idx + 1 );
			if ( $this->colspan[ $row_idx ] > 1 ) { // we have colspaned cells
				$span_attr .= " colspan=\"{$this->colspan[ $row_idx ]}\"";
				$cell_class .= " colspan-{$this->colspan[ $row_idx ]}";
			}
			if ( $this->rowspan[ $col_idx ] > 1 ) { // we have rowspaned cells
				$span_attr .= " rowspan=\"{$this->rowspan[ $col_idx ]}\"";
				$cell_class .= " rowspan-{$this->rowspan[ $col_idx ]}";
			}

			$cell_class = apply_filters( 'tablepress_cell_css_class', $cell_class, $this->table['id'], $cell_content, $row_idx + 1, $col_idx + 1, $this->colspan[ $row_idx ], $this->rowspan[ $col_idx ] );
			$class_attr = ( ! empty( $col_class ) ) ? " class=\"{$col_class}\"" : '';
			$style_attr = ( ( 0 == $row_idx ) && ! empty( $this->render_options['column_widths'][$col_idx] ) ) ? " style=\"width:{$this->render_options['column_widths'][$col_idx]};\"" : '';

			if ( $this->render_options['first_column_th'] && 0 == $col_idx )
				$tag = 'th';

			$row_cells[] = "<{$tag}{$span_attr}{$class_attr}{$style_attr}>{$cell_content}</${tag}>";
			$this->colspan[ $row_idx ] = 1; // reset
			$this->rowspan[ $col_idx ] = 1; // reset
		}

		// @TODO: Maybe apply row-$row_idx and alternate colors classes only to body rows?
		$row_class = 'row-' . ( $row_idx + 1 ) ;
		if ( $this->render_options['alternating_row_colors'] )
			$row_class .= ( 1 == ($row_idx % 2) ) ? ' even' : ' odd';
		$row_class = apply_filters( 'tablepress_row_css_class', $row_class, $this->table['id'], $row_cells, $row_idx + 1 );
		if ( ! empty( $row_class ) )
			$row_class = " class=\"{$row_class}\"";

		$row_cells = array_reverse( $row_cells ); // because we looped through the cells in reverse order
		return "\t<tr{$row_class}>\n\t\t" . implode( '', $row_cells ) . "\n\t</tr>\n";
	}

	/**
	 * Possibly replace certain HTML entities and replace line breaks with HTML
	 *
	 * @TODO: Find a better solution than this function, e.g. something like wpautop()
	 *
	 * @param string $string The string to process
	 * @return string Processed string for output
	 */
	protected function safe_output( $string ) {
		// replace any & with &amp; that is not already an encoded entity (from function htmlentities2 in WP 2.8)
		// complete htmlentities2() or htmlspecialchars() would encode <HTML> tags, which we don't want
		$string = preg_replace( "/&(?![A-Za-z]{0,4}\w{2,3};|#[0-9]{2,4};)/", "&amp;", $string );
		// substitute line breaks with HTML <br> tags, nl2br can be overwritten to false, if not wanted
		if ( apply_filters( 'tablepress_apply_nl2br', true, $this->table['id'] ) )
			$string = nl2br( $string );
		return $string;
	}

	/**
	 * Get the default render options
	 *
	 * @since 1.0.0
	 *
	 * @return array Default render options
	 */
	public function get_default_render_options() {
	    return array(
            'id' => 0,
            'column_widths' => array(),
            'alternating_row_colors' => -1,
            'row_hover' => -1,
            'table_head' => -1,
            'first_column_th' => false,
            'table_foot' => -1,
            'print_name' => -1,
            'print_description' => -1,
            'cache_table_output' => -1,
            'extra_css_classes' => '', //@TODO: sanitize this parameter, if set
            'use_datatables' => -1,
            'datatables_sort' => -1,
            'datatables_paginate' => -1,
            'datatables_paginate_entries' => -1,
            'datatables_lengthchange' => -1,
            'datatables_filter' => -1,
            'datatables_info' => -1,
            'datatables_tabletools' => -1,
            'datatables_custom_commands' => -1,
            'row_offset' => 1, // ATTENTION: MIGHT BE DROPPED IN FUTURE VERSIONS!
            'row_count' => null, // ATTENTION: MIGHT BE DROPPED IN FUTURE VERSIONS!
            'show_rows' => array(),
            'show_columns' => array(),
            'hide_rows' => array(),
            'hide_columns' => array(),
            'cellspacing' => false,
            'cellpadding' => false,
            'border' => false,
            'html_id' => 'test'
        );
	}

	/**
	 * Get the CSS code for the Preview iframe
	 *
	 * @since 1.0.0
	 *
	 * @return string CSS for the Preview iframe
	 */
	public function get_preview_css() {
		return <<<CSS
<style type="text/css">
.tablepress {
	border-collapse: collapse;
	border: 2px solid #000000;
	margin: 10px auto;
}
.tablepress td,
.tablepress th {
	box-sizing: border-box;
	width: 200px;
	border: 1px solid #dddddd;
	padding: 3px;
}
.tablepress thead tr,
.tablepress tfoot tr {
	background-color: #e6eeee;
}
.tablepress tbody tr.even {
	background-color: #ffffff;
}
.tablepress tbody tr.odd {
	background-color: #eeeeee;
}
.tablepress .row-hover tr:hover {
	background-color: #d0d0d6;
}
</style>
CSS;
	}

} // class TablePress_Render