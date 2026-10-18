<?php
/**
 * Berechtigungen.
 *
 * @package Lsec
 */

namespace Lsec\Infrastructure\Security;

/**
 * Eigene Capability "lsec_manage", standardmäßig nur für Administratoren.
 */
final class Capabilities {

	public const MANAGE = 'lsec_manage';

	/**
	 * Fügt die Capability der Administratorrolle hinzu.
	 */
	public static function add(): void {
		$role = get_role( 'administrator' );
		if ( null !== $role && ! $role->has_cap( self::MANAGE ) ) {
			$role->add_cap( self::MANAGE );
		}
	}

	/**
	 * Entfernt die Capability aus allen Rollen.
	 */
	public static function remove(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( self::MANAGE ) ) {
				$role->remove_cap( self::MANAGE );
			}
		}
	}

	/**
	 * Darf der aktuelle Benutzer das Plugin verwalten?
	 */
	public static function current_user_can_manage(): bool {
		return current_user_can( self::MANAGE );
	}
}
