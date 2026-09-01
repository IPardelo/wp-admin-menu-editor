<?php
/**
 * Plugin Name:       Wordpress admin-menu editor
 * Plugin URI:        https://github.com/IPardelo/wp-admin-menu-editor
 * Description:       Plugin de WordPress que permite editar usuario a usuario o que cada un ve no panel de administración.
 * Version:           1.0.0
 * Requires at least: 5.6
 * Requires PHP:      7.2
 * Author:            IPardelo
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-admin-menu-editor
 *
 * NOTA IMPORTANTE: este plugin oculta elementos do menú, non revoca permisos.
 * Un usuario que coñeza a URL directa (p. ej. /wp-admin/plugins.php) seguirá
 * podendo acceder si o seu rol llo permite. Para bloqueo real hay que retirar
 * capacidades o rol (este plugin non o fai).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPAME_VERSION', '1.0.0' );
define( 'WPAME_META_KEY', '_wpame_menus_ocultos' );
define( 'WPAME_PAGE_SLUG', 'wp-admin-menu-editor' );

/**
 * Clase principal del plugin.
 */
final class WPAME_Admin_Menu_Editor {

	/** @var WPAME_Admin_Menu_Editor|null */
	private static $instance = null;

	/** @var array Copia del menú completo antes de ocultar nada. */
	private $menu_completo = array();

	/** @var array Copia de los submenús completos antes de ocultar nada. */
	private $submenu_completo = array();

	/**
	 * Singleton.
	 *
	 * @return WPAME_Admin_Menu_Editor
	 */
	public static function instancia() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'registrar_pagina' ) );
		// Prioridad muy alta: capturamos el menú una vez que todos los plugins lo han montado.
		add_action( 'admin_menu', array( $this, 'capturar_menu' ), 9998 );
		add_action( 'admin_menu', array( $this, 'ocultar_menus' ), 9999 );
		add_action( 'admin_post_wpame_guardar', array( $this, 'guardar' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'enlace_ajustes' ) );
		add_action( 'delete_user', array( $this, 'limpiar_usuario' ) );
	}

	/* ---------------------------------------------------------------------
	 * Rexistro da pantalla de axustes
	 * ------------------------------------------------------------------ */

	/**
	 * Engade a páxina baixo "Usuarios".
	 */
	public function registrar_pagina() {
		add_users_page(
			__( 'Wordpress admin-menu editor', 'wp-admin-menu-editor' ),
			__( 'Wordpress admin-menu editor', 'wp-admin-menu-editor' ),
			'edit_users',
			WPAME_PAGE_SLUG,
			array( $this, 'render_pagina' )
		);
	}

	/**
	 * Ligazón "Axustes" na lista de plugins.
	 *
	 * @param array $enlaces Enlaces existentes.
	 * @return array
	 */
	public function enlace_ajustes( $enlaces ) {
		$url = admin_url( 'users.php?page=' . WPAME_PAGE_SLUG );
		array_unshift( $enlaces, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Axustes', 'wp-admin-menu-editor' ) . '</a>' );
		return $enlaces;
	}

	/* ---------------------------------------------------------------------
	 * Captura e ocultación do menú
	 * ------------------------------------------------------------------ */

	/**
	 * Garda unha copia do menú completo antes de aplicar ninguna ocultación.
	 */
	public function capturar_menu() {
		global $menu, $submenu;

		$this->menu_completo    = is_array( $menu ) ? $menu : array();
		$this->submenu_completo = is_array( $submenu ) ? $submenu : array();
	}

	/**
	 * Oculta do menú lateral os elementos configurados para o usuario actual.
	 */
	public function ocultar_menus() {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return;
		}

		$ocultos = $this->obtener_ocultos( $user_id );

		if ( empty( $ocultos['top'] ) && empty( $ocultos['sub'] ) ) {
			return;
		}

		// Nunca ocultamos a propia pantalla del plugin a quien pode xestionarla:
		// evita que algún despistao se quede sin forma de revertir a configuración.
		$protegido_parent = 'users.php';
		$protegido_slug   = WPAME_PAGE_SLUG;

		foreach ( $ocultos['top'] as $slug ) {
			remove_menu_page( $slug );
		}

		foreach ( $ocultos['sub'] as $padre => $slugs ) {
			foreach ( $slugs as $slug ) {
				if ( $padre === $protegido_parent && $slug === $protegido_slug && current_user_can( 'edit_users' ) ) {
					continue;
				}
				remove_submenu_page( $padre, $slug );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Datos
	 * ------------------------------------------------------------------ */

	/**
	 * Devolve a configuración dun usuario, xa normalizada.
	 *
	 * @param int $user_id ID de usuario.
	 * @return array{top: string[], sub: array<string, string[]>}
	 */
	public function obtener_ocultos( $user_id ) {
		$datos = get_user_meta( $user_id, WPAME_META_KEY, true );

		$salida = array(
			'top' => array(),
			'sub' => array(),
		);

		if ( ! is_array( $datos ) ) {
			return $salida;
		}

		if ( ! empty( $datos['top'] ) && is_array( $datos['top'] ) ) {
			$salida['top'] = array_values( array_unique( array_map( 'strval', $datos['top'] ) ) );
		}

		if ( ! empty( $datos['sub'] ) && is_array( $datos['sub'] ) ) {
			foreach ( $datos['sub'] as $padre => $slugs ) {
				if ( ! is_array( $slugs ) ) {
					continue;
				}
				$salida['sub'][ (string) $padre ] = array_values( array_unique( array_map( 'strval', $slugs ) ) );
			}
		}

		return $salida;
	}

	/**
	 * Borra a configuración cando se elimina un usuario.
	 *
	 * @param int $user_id ID de usuario.
	 */
	public function limpiar_usuario( $user_id ) {
		delete_user_meta( $user_id, WPAME_META_KEY );
	}

	/**
	 * Procesa o formulario.
	 */
	public function guardar() {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Non tes permisos para facer isto.', 'wp-admin-menu-editor' ), 403 );
		}

		$user_id = isset( $_POST['wpame_user_id'] ) ? absint( $_POST['wpame_user_id'] ) : 0;

		check_admin_referer( 'wpame_guardar_' . $user_id );

		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_die( esc_html__( 'Usuario non válido.', 'wp-admin-menu-editor' ), 400 );
		}

		$top = array();
		if ( isset( $_POST['wpame_top'] ) && is_array( $_POST['wpame_top'] ) ) {
			foreach ( wp_unslash( $_POST['wpame_top'] ) as $slug ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$slug = $this->limpiar_slug( $slug );
				if ( '' !== $slug ) {
					$top[] = $slug;
				}
			}
		}

		$sub = array();
		if ( isset( $_POST['wpame_sub'] ) && is_array( $_POST['wpame_sub'] ) ) {
			foreach ( wp_unslash( $_POST['wpame_sub'] ) as $padre => $slugs ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$padre = $this->limpiar_slug( $padre );
				if ( '' === $padre || ! is_array( $slugs ) ) {
					continue;
				}
				foreach ( $slugs as $slug ) {
					$slug = $this->limpiar_slug( $slug );
					if ( '' !== $slug ) {
						$sub[ $padre ][] = $slug;
					}
				}
			}
		}

		if ( empty( $top ) && empty( $sub ) ) {
			delete_user_meta( $user_id, WPAME_META_KEY );
		} else {
			update_user_meta(
				$user_id,
				WPAME_META_KEY,
				array(
					'top' => array_values( array_unique( $top ) ),
					'sub' => $sub,
				)
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => WPAME_PAGE_SLUG,
					'user_id'   => $user_id,
					'wpame_aviso' => 'guardado',
				),
				admin_url( 'users.php' )
			)
		);
		exit;
	}

	/**
	 * Limpia un slug de menú conservando os parámetros de consulta lexítimos.
	 *
	 * @param mixed $slug Slug sin procesar.
	 * @return string
	 */
	private function limpiar_slug( $slug ) {
		if ( ! is_string( $slug ) ) {
			return '';
		}
		$slug = trim( wp_strip_all_tags( $slug ) );
		// Los slugs de WordPress son rutas tipo "edit.php?post_type=page" o "options-general.php".
		$slug = preg_replace( '/[^A-Za-z0-9_\-\.\?\=\&\/\%:]/', '', $slug );
		return (string) $slug;
	}

	/* ---------------------------------------------------------------------
	 * Interfaz
	 * ------------------------------------------------------------------ */

	/**
	 * Carga os estilos e scripts sólo na nuestra pantalla.
	 *
	 * @param string $hook Hook de la pantalla actual.
	 */
	public function assets( $hook ) {
		if ( 'users_page_' . WPAME_PAGE_SLUG !== $hook ) {
			return;
		}

		wp_register_style( 'wpame-admin', false, array(), WPAME_VERSION );
		wp_enqueue_style( 'wpame-admin' );
		wp_add_inline_style( 'wpame-admin', $this->css() );

		wp_register_script( 'wpame-admin', false, array(), WPAME_VERSION, true );
		wp_enqueue_script( 'wpame-admin' );
		wp_add_inline_script( 'wpame-admin', $this->js() );
	}

	/**
	 * Limpia o título dun elemento de menú (quita contadores e HTML).
	 *
	 * @param string $titulo Título original.
	 * @return string
	 */
	private function titulo_limpio( $titulo ) {
		$titulo = preg_replace( '#<span[^>]*>.*?</span>#is', '', (string) $titulo );
		$titulo = wp_strip_all_tags( $titulo );
		$titulo = trim( html_entity_decode( $titulo, ENT_QUOTES, 'UTF-8' ) );
		return '' === $titulo ? __( '(sen nome)', 'wp-admin-menu-editor' ) : $titulo;
	}

	/**
	 * Pinta a pantalla de axustes.
	 */
	public function render_pagina() {
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Non tes permisos para ver esta páxina.', 'wp-admin-menu-editor' ), 403 );
		}

		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$usuario = $user_id ? get_userdata( $user_id ) : false;
		$ocultos = $usuario ? $this->obtener_ocultos( $user_id ) : array(
			'top' => array(),
			'sub' => array(),
		);

		$aviso = isset( $_GET['wpame_aviso'] ) ? sanitize_key( wp_unslash( $_GET['wpame_aviso'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap wpame-wrap">
			<h1><?php esc_html_e( 'Wordpress admin-menu editor', 'wp-admin-menu-editor' ); ?></h1>

			<?php if ( 'guardado' === $aviso ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Configuración gardada.', 'wp-admin-menu-editor' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Escolle un usuario e marca os elementos que lle queres agochar do menú lateral do escritorio. A ocultación é visual: non retira permisos, así que quen coñeza o URL directo poderá seguir entrando se o seu rol llo permite.', 'wp-admin-menu-editor' ); ?>
			</p>

			<form method="get" action="<?php echo esc_url( admin_url( 'users.php' ) ); ?>" class="wpame-selector">
				<input type="hidden" name="page" value="<?php echo esc_attr( WPAME_PAGE_SLUG ); ?>" />
				<label for="wpame-user-select"><strong><?php esc_html_e( 'Usuario:', 'wp-admin-menu-editor' ); ?></strong></label>
				<?php
				wp_dropdown_users(
					array(
						'name'              => 'user_id',
						'id'                => 'wpame-user-select',
						'selected'          => $user_id,
						'show_option_none'  => __( '— Escolle un usuario —', 'wp-admin-menu-editor' ),
						'option_none_value' => 0,
						'show'              => 'display_name_with_login',
					)
				);
				?>
				<?php submit_button( __( 'Cargar', 'wp-admin-menu-editor' ), 'secondary', '', false ); ?>
			</form>

			<?php
			if ( ! $usuario ) {
				$this->render_resumen();
				echo '</div>';
				return;
			}
			?>

			<hr />

			<h2>
				<?php
				/* translators: %s: nome do usuario */
				printf( esc_html__( 'Configuración de %s', 'wp-admin-menu-editor' ), '<em>' . esc_html( $usuario->display_name ) . '</em>' );
				?>
			</h2>
			<p class="description">
				<?php
				$roles_txt = ! empty( $usuario->roles ) ? implode( ', ', $usuario->roles ) : __( 'ningún', 'wp-admin-menu-editor' );
				/* translators: %s: lista de roles */
				printf( esc_html__( 'Roles: %s', 'wp-admin-menu-editor' ), esc_html( $roles_txt ) );
				?>
			</p>

			<?php if ( get_current_user_id() === $user_id ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Estás a editar a túa propia configuración. Se agochas "Usuarios" perderás o acceso rápido a esta pantalla (poderás volver polo URL directo).', 'wp-admin-menu-editor' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wpame_guardar" />
				<input type="hidden" name="wpame_user_id" value="<?php echo esc_attr( $user_id ); ?>" />
				<?php wp_nonce_field( 'wpame_guardar_' . $user_id ); ?>

				<div class="wpame-toolbar">
					<input type="search" id="wpame-buscador" placeholder="<?php esc_attr_e( 'Filtrar elementos…', 'wp-admin-menu-editor' ); ?>" />
					<button type="button" class="button" data-wpame-accion="todo"><?php esc_html_e( 'Marcar todo', 'wp-admin-menu-editor' ); ?></button>
					<button type="button" class="button" data-wpame-accion="nada"><?php esc_html_e( 'Desmarcar todo', 'wp-admin-menu-editor' ); ?></button>
				</div>

				<div class="wpame-lista">
					<?php $this->render_arbol( $ocultos ); ?>
				</div>

				<?php submit_button( __( 'Gardar cambios', 'wp-admin-menu-editor' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Pinta o árbol de menús cas casillas de selección.
	 *
	 * @param array $ocultos Configuración actual del usuario.
	 */
	private function render_arbol( $ocultos ) {
		if ( empty( $this->menu_completo ) ) {
			echo '<p>' . esc_html__( 'Non se puido ler o menú de administración.', 'wp-admin-menu-editor' ) . '</p>';
			return;
		}

		foreach ( $this->menu_completo as $item ) {
			if ( empty( $item[0] ) && empty( $item[2] ) ) {
				continue;
			}

			// Saltamos separadores.
			if ( isset( $item[4] ) && false !== strpos( (string) $item[4], 'wp-menu-separator' ) ) {
				continue;
			}

			$slug   = (string) $item[2];
			$titulo = $this->titulo_limpio( $item[0] );

			if ( '' === $slug ) {
				continue;
			}

			$marcado_top = in_array( $slug, $ocultos['top'], true );
			$hijos       = isset( $this->submenu_completo[ $slug ] ) ? $this->submenu_completo[ $slug ] : array();
			?>
			<div class="wpame-grupo">
				<label class="wpame-padre">
					<input type="checkbox" name="wpame_top[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $marcado_top ); ?> />
					<span class="wpame-nombre"><?php echo esc_html( $titulo ); ?></span>
					<code><?php echo esc_html( $slug ); ?></code>
				</label>

				<?php if ( ! empty( $hijos ) ) : ?>
					<ul class="wpame-hijos">
						<?php
						foreach ( $hijos as $hijo ) {
							if ( empty( $hijo[2] ) ) {
								continue;
							}
							$slug_hijo    = (string) $hijo[2];
							$titulo_hijo  = $this->titulo_limpio( $hijo[0] );
							$marcado_hijo = isset( $ocultos['sub'][ $slug ] ) && in_array( $slug_hijo, $ocultos['sub'][ $slug ], true );
							?>
							<li>
								<label>
									<input type="checkbox" name="wpame_sub[<?php echo esc_attr( $slug ); ?>][]" value="<?php echo esc_attr( $slug_hijo ); ?>" <?php checked( $marcado_hijo ); ?> />
									<span class="wpame-nombre"><?php echo esc_html( $titulo_hijo ); ?></span>
									<code><?php echo esc_html( $slug_hijo ); ?></code>
								</label>
							</li>
							<?php
						}
						?>
					</ul>
				<?php endif; ?>
			</div>
			<?php
		}
	}

	/**
	 * Tabla resumen de usuarios que xa teñen restriccións.
	 */
	private function render_resumen() {
		$usuarios = get_users(
			array(
				'meta_key'     => WPAME_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_compare' => 'EXISTS',
				'number'       => 200,
			)
		);

		echo '<h2>' . esc_html__( 'Usuarios con elementos agochados', 'wp-admin-menu-editor' ) . '</h2>';

		if ( empty( $usuarios ) ) {
			echo '<p>' . esc_html__( 'Aínda non hai ningún usuario con restricións.', 'wp-admin-menu-editor' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped wpame-resumen"><thead><tr>';
		echo '<th>' . esc_html__( 'Usuario', 'wp-admin-menu-editor' ) . '</th>';
		echo '<th>' . esc_html__( 'Menús principais agochados', 'wp-admin-menu-editor' ) . '</th>';
		echo '<th>' . esc_html__( 'Submenús agochados', 'wp-admin-menu-editor' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $usuarios as $u ) {
			$conf  = $this->obtener_ocultos( $u->ID );
			$n_sub = 0;
			foreach ( $conf['sub'] as $slugs ) {
				$n_sub += count( $slugs );
			}
			$url = add_query_arg(
				array(
					'page'    => WPAME_PAGE_SLUG,
					'user_id' => $u->ID,
				),
				admin_url( 'users.php' )
			);

			echo '<tr>';
			echo '<td>' . esc_html( $u->display_name ) . ' <span class="description">(' . esc_html( $u->user_login ) . ')</span></td>';
			echo '<td>' . esc_html( (string) count( $conf['top'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $n_sub ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Editar', 'wp-admin-menu-editor' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * CSS de la pantalla.
	 *
	 * @return string
	 */
	private function css() {
		return '
		.wpame-selector { margin: 16px 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
		.wpame-toolbar { margin: 12px 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
		.wpame-toolbar input[type=search] { min-width: 260px; }
		.wpame-lista { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 8px 16px; max-width: 900px; }
		.wpame-grupo { padding: 10px 0; border-bottom: 1px solid #f0f0f1; }
		.wpame-grupo:last-child { border-bottom: 0; }
		.wpame-padre { font-weight: 600; display: inline-flex; align-items: center; gap: 8px; }
		.wpame-hijos { margin: 6px 0 0 28px; }
		.wpame-hijos li { margin: 2px 0; }
		.wpame-hijos label { display: inline-flex; align-items: center; gap: 8px; }
		.wpame-lista code { background: #f6f7f7; color: #646970; font-size: 11px; padding: 1px 5px; border-radius: 3px; }
		.wpame-grupo.wpame-oculto { display: none; }
		.wpame-hijos li.wpame-oculto { display: none; }
		.wpame-resumen { max-width: 900px; margin-top: 12px; }
		';
	}

	/**
	 * JS da pantalla (sin dependencias).
	 *
	 * @return string
	 */
	private function js() {
		return <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
	var lista = document.querySelector('.wpame-lista');
	if (!lista) { return; }

	// Marcar / desmarcar todo (sólo o visible tras filtrar).
	document.querySelectorAll('[data-wpame-accion]').forEach(function (boton) {
		boton.addEventListener('click', function () {
			var valor = boton.getAttribute('data-wpame-accion') === 'todo';
			lista.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
				var fila = cb.closest('li') || cb.closest('.wpame-grupo');
				if (fila && fila.classList.contains('wpame-oculto')) { return; }
				cb.checked = valor;
			});
		});
	});

	// O marcar un menú principal marcanse os seus submenús.
	lista.querySelectorAll('.wpame-padre input[type=checkbox]').forEach(function (padre) {
		padre.addEventListener('change', function () {
			var grupo = padre.closest('.wpame-grupo');
			if (!grupo) { return; }
			grupo.querySelectorAll('.wpame-hijos input[type=checkbox]').forEach(function (hijo) {
				hijo.checked = padre.checked;
			});
		});
	});

	// Filtro de texto.
	var buscador = document.getElementById('wpame-buscador');
	if (buscador) {
		buscador.addEventListener('input', function () {
			var q = buscador.value.toLowerCase().trim();
			lista.querySelectorAll('.wpame-grupo').forEach(function (grupo) {
				var textoGrupo = grupo.textContent.toLowerCase();
				var coincideGrupo = q === '' || textoGrupo.indexOf(q) !== -1;
				grupo.classList.toggle('wpame-oculto', !coincideGrupo);

				grupo.querySelectorAll('.wpame-hijos li').forEach(function (li) {
					var etiquetaPadre = grupo.querySelector('.wpame-padre .wpame-nombre');
					var padreCoincide = q !== '' && etiquetaPadre && etiquetaPadre.textContent.toLowerCase().indexOf(q) !== -1;
					var coincide = q === '' || padreCoincide || li.textContent.toLowerCase().indexOf(q) !== -1;
					li.classList.toggle('wpame-oculto', !coincide);
				});
			});
		});
	}
});
JS;
	}
}

WPAME_Admin_Menu_Editor::instancia();