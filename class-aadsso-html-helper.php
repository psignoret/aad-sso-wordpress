<?php
/**
 * File class-aadsso-html-helper.php
 *
 * @package AADSSO
 */

/**
 * Helper class for generating HTML.
 */
class AADSSO_Html_Helper {
	/**
	 * Return an HTML tag
	 *
	 * @param string $tag The tag name.
	 * @param array  $attrs The $key => $value attributes to add to the tag. $key => boolean will add a valueless attribute.
	 * @param string $content The content of the tag. Will not be escaped.  If null, the tag will be self-closing.
	 */
	public static function get_tag( $tag, $attrs, $content = '' ) {
		$attr_strs   = array();
		$attr_sprint = '%1$s="%2$s"';
		foreach ( $attrs as $att => $val ) {
			$valtype = gettype( $val );

			if ( 'boolean' === $valtype ) {
				// This is a valueless attribute like [disabled] or [autofocus].
				if ( true === $val ) {
					$attr_strs[] = esc_attr( $att );
				}
			} elseif ( 'string' === $valtype ) {
				$attr_strs[] = sprintf( $attr_sprint, esc_attr( $att ), esc_attr( $val ) );
			}
		}

		$tag_sprint = null === $content
			? '<%1$s %2$s>' // self-closing/no-content tag.
			: '<%1$s %2$s>%3$s</%1$s>'; // tag with content.

		return sprintf( $tag_sprint, $tag, implode( ' ', $attr_strs ), $content );
	}

	/**
	 * Shorthand for `echo AADSSO_Html_Helper::get_tag( $tag, $attrs, $content )`
	 */
	public static function tag() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo self::get_tag( ...func_get_args() );
	}
}
