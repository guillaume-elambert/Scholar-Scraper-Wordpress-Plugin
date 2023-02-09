<?php

use Model\ScholarAuthor;
use Model\ScholarAuthorCollection;
use Model\ScholarPublication;
use Model\ScholarPublicationCollection;


// Cron related actions
add_action( CRON_HOOK_NAME, 'scholar_scraper_start_scraping', 10, 0 );


/**
 * Fonction permettant d'installer les dépendances du script python.
 * @return bool True si l'installation s'est bien déroulée, false sinon.
 * @since 1.0.0
 */
function scholar_scraper_install_requirements(): bool {

	// On vérifie que le fichier requirements.txt existe
	if ( ! is_file( PYTHON_REQUIREMENTS_PATH ) || ! is_readable( PYTHON_REQUIREMENTS_PATH ) ) {
		return false;
	}

	// On vérifie que le chemin vers pip est correct
	$pipPath = scholar_scraper_get_setting_value( 'PIP_PATH' );
	if ( ! $pipPath || ! is_executable( $pipPath ) ) {
		return false;
	}

	// On lance la commande d'installation des dépendances
	list( $res, $ret_val ) = scholar_scraper_run_command_try_all_methods(
		sprintf( "%s install -r %s",
			scholar_scraper_get_setting_value( 'PIP_PATH' ),
			PYTHON_REQUIREMENTS_PATH
		)
	);

	// La valeur retournée est 0 : l'installation s'est bien déroulée
	return $ret_val == 0;
}

/**
 * Fonction permettant d'exécuter le script python qui récupère les données de Google Scholar.
 *
 * @return mixed|string Le résultat de l'exécution du script python.
 * @throws ReflectionException Si un problème survient lors de la création d'un objet.
 * @since 1.0.0
 */
function scholar_scraper_start_scraping() {

	// Check if the cron is already executing
	if ( get_transient( CRON_TRANSIENT ) ) {
		scholar_scraper_log( LOG_TYPE::INFO, "Cron already executing." );

		return false;
	}

	set_transient( CRON_TRANSIENT, true, CRON_TRANSIENT_RESET_AFTER );

	// On vérifie que le script python existe
	if ( ! is_file( PYTHON_SCRIPT_PATH ) || ! is_readable( PYTHON_SCRIPT_PATH ) ) {
		delete_transient( CRON_TRANSIENT );
		scholar_scraper_log( LOG_TYPE::ERROR, "Python script not found" );

		return "";
	}

	// On vérifie que le chemin vers python est correct
	$pythonPath = scholar_scraper_get_setting_value( 'PYTHON_PATH' );
	if ( ! $pythonPath || ! is_executable( $pythonPath ) ) {
		delete_transient( CRON_TRANSIENT );
		scholar_scraper_log( LOG_TYPE::ERROR, "Python path not found" );

		return "";
	}

	// On vérifié qu'on a bien accès à la base de données WordPress
	global $wpdb;
	if ( ! isset( $wpdb ) ) {
		delete_transient( CRON_TRANSIENT );
		scholar_scraper_log( LOG_TYPE::ERROR, "Database not found" );

		return "";
	}

	// On s'assure que les dépendances Python sont bien installées
	if ( ! scholar_scraper_install_requirements() ) {
		delete_transient( CRON_TRANSIENT );
		scholar_scraper_log( LOG_TYPE::ERROR, "Python requirements not installed" );

		return false;
	}

	# TODO: Get the scholar users id from the database
	$scholarUsers = array( "1iQtvdsAAAAJ", "dAKCYJgAAAAJ" );

	// On vérifie qu'on a bien récupéré des utilisateurs
	if ( ! count( $scholarUsers ) ) {
		delete_transient( CRON_TRANSIENT );
		scholar_scraper_log( LOG_TYPE::ERROR, "No scholar users found" );

		return "";
	}

	$scraperArguments = "";

	# Creating a string with all the scholar users id separated by a space
	foreach ( $scholarUsers as $scholarUser ) {
		$scraperArguments .= $scholarUser . " ";
	}

	// On formate la commande à exécuter
	$command = sprintf(
		"%s %s %s 2>&1",
		scholar_scraper_get_setting_value( 'PYTHON_PATH' ),
		PYTHON_SCRIPT_PATH,
		$scraperArguments
	);

	// On exécute la commande
	list( $res, $ret_var ) = scholar_scraper_run_command_try_all_methods( $command );

	// On vérifie que la commande s'est bien déroulée, sinon on sort de la fonction
	if ( $ret_var != 0 ) {
		delete_transient( CRON_TRANSIENT );

		return "";
	}

	// On écrit le résultat dans un fichier
	scholar_scraper_write_in_file( RESULTS_FILE, $res, false );

	// On décode le résultat en objets PHP
	$decodedRes = scholar_scraper_decode_results( $res );

	// On serialise le résultat
	$serialized = serialize( $decodedRes );

	// On écrit le résultat sérialisé dans un fichier
	scholar_scraper_write_in_file( SERIALIZED_RESULTS_FILE, $serialized, false );


	delete_transient( CRON_TRANSIENT );

	return $res;
}


/**
 * Fonction permettant d'afficher le résultat de l'exécution du script python.
 *
 * @param mixed $atts Les attributs du shortcode.
 *
 * @return string Le résultat de l'exécution du script python.
 * @throws ReflectionException Si une erreur survient lors de la récupération des objets.
 * @since 1.0.0
 */
function scholar_scraper_display_result( mixed $atts ): string {

	//echo "<pre>" . print_r( $atts, true ) . "</pre>";

	// Get the attributes passed to the shortcode
	$atts = shortcode_atts(
		array(
			'number_papers_to_show' => DEFAULT_NUMBER_OF_PAPERS_TO_SHOW,
			'sort_by_field'         => DEFAULT_SORT_FIELD,
			'sort_by_direction'     => 'desc',
		),
		$atts,
		'scholar_scraper'
	);

	foreach ( $atts as $key => $value ) {
		if ( is_null( $value ) ) {
			continue;
		}

		$atts[ $key ] = html_entity_decode( $value );
	}

	// On vérifie que l'attribut number_papers_to_show est bien un nombre
	if ( ! is_numeric( trim( $atts['number_papers_to_show'] ) ) ) {
		$atts['number_papers_to_show'] = DEFAULT_NUMBER_OF_PAPERS_TO_SHOW;
	}

	// On récupère le nombre de publications à afficher
	$nbPapersToShow = ( (int) $atts['number_papers_to_show'] ) - 1;


	// On vérifie que l'attribut sort_by_field est bien un champ de la classe ScholarPublication
	$posibleSortFields = ScholarPublication::get_non_array_fields();
	if ( ! array_key_exists( strtolower( trim( $atts['sort_by_field'] ) ), $posibleSortFields ) ) {
		$atts['sort_by_field'] = DEFAULT_SORT_FIELD;
	}

	$sortField = $atts['sort_by_field'];


	// On vérifie que l'attribut sort_by_direction est bien une direction de tri possible
	$posibleSortDirections = array( 'asc', 'desc' );
	if ( ! in_array( strtolower( trim( $atts['sort_by_direction'] ) ), $posibleSortDirections ) ) {
		$atts['sort_by_direction'] = DEFAULT_SORT_DIRECTION;
	}

	$sortDirection = $atts['sort_by_direction'];


	// Entrée : le fichier contenant les résultats sérialisés n'existe pas ou n'est pas lisible
	//       => On essaie de voir si le fichier contenant les résultats non sérialisés existe et est lisible
	if ( ! is_file( SERIALIZED_RESULTS_FILE ) || ! is_readable( SERIALIZED_RESULTS_FILE ) ) {

		// Entrée : le fichier contenant les résultats non sérialisés n'existe pas ou n'est pas lisible
		//       => On affiche un message d'erreur
		if ( ! is_file( RESULTS_FILE ) || ! is_readable( RESULTS_FILE ) ) {
			return "<p>Unfortunately, our researchers are currently on vacation...<br/>Please try again later.</p>";
		}

		$res = file_get_contents( RESULTS_FILE );

		// On décode le résultat en objets PHP
		$decodedRes = scholar_scraper_decode_results( $res );

		// On serialise le résultat
		$serialized = serialize( $decodedRes );

		// On écrit le résultat sérialisé dans un fichier
		scholar_scraper_write_in_file( SERIALIZED_RESULTS_FILE, $serialized, false );

	}

	// Get the content of the result file
	$res                           = file_get_contents( SERIALIZED_RESULTS_FILE );
	$res                           = unserialize( $res );
	$scholarPublicationsCollection = new ScholarPublicationCollection();

	// Add all the publications of all the users to the collection
	foreach ( $res as $scholarUser ) {
		$scholarPublicationsCollection->add( ...$scholarUser->publications->values() );
	}

	$totalPapers = $scholarPublicationsCollection->count();

	// Order the publications by $atts['sort_by_field']. If the field is the same, the alphabetical order is used on title.
	// If the $atts['sort_by_field'] is not set, the publication is put at the end of the list.
	$scholarPublicationsCollection->usort( function ( $a, $b ) use ( $sortField, $sortDirection ): int {
		if ( ! isset( $a ) || ! isset( $b ) ) {
			return 0;
		}

		// Si les deux publications n'ont pas de valeur pour le champ de tri, on trie par ordre alphabétique sur le titre
		if ( ! isset( $a->$sortField ) && ! isset( $b->$sortField ) ) {
			// Tri alphabétique sur le titre en fonction de la direction de tri
			if ( $sortDirection === 'desc' ) {
				return strcmp( $b->title, $a->title );
			}

			return strcmp( $a->title, $b->title );
		}


		// Si la première publication n'a pas de valeur pour le champ de tri, on la met à la fin de la liste
		if ( ! isset( $a->$sortField ) ) {
			return 1;
		}

		// Si la deuxième publication n'a pas de valeur pour le champ de tri, on la met à la fin de la liste
		if ( ! isset( $b->$sortField ) ) {
			return - 1;
		}

		// Si les deux publications ont la même valeur pour le champ de tri, on trie par ordre alphabétique sur le titre
		if ( $a->$sortField === $b->$sortField ) {
			// Tri alphabétique sur le titre en fonction de la direction de tri
			if ( $sortDirection === 'desc' ) {
				return strcmp( $b->title, $a->title );
			}

			return strcmp( $a->title, $b->title );
		}


		// Tri en fonction de la direction de tri
		if ( $sortDirection === 'desc' ) {
			return $b->$sortField <=> $a->$sortField;
		}

		return $a->$sortField <=> $b->$sortField;
	} );

	// On affiche les publications
	$toReturn = "<div class='scholar-scraper-publications'>";

	for ( $i = 0; $i <= $nbPapersToShow && $i < $totalPapers; $i ++ ) {

		$publication = $scholarPublicationsCollection->get( $i );
		if ( ! isset( $publication ) || ! isset( $publication->title ) ) {
			continue;
		}

		ob_start();
		include( PLUGIN_PATH . 'src/Template/PublicationCardTemplate.php' );
		$toReturn .= ob_get_clean();

	}

	$toReturn .= "</div>";

	return $toReturn;
}


/**
 * Fonction permettant de récupérer le résultat de l'exécution du script python.
 *
 * @return ScholarAuthorCollection Le résultat de l'exécution du script python : une collection d'auteurs.
 *
 * @throws ReflectionException Si une erreur survient lors de la création d'un objet.
 * @since 1.0.0
 */
function scholar_scraper_decode_results( string $results ): ScholarAuthorCollection {
	$results = json_decode( $results, true );

	$scholarUsers = new ScholarAuthorCollection();

	foreach ( $results as $user ) {
		$scholarUser = scholar_scraper_cast_object_to_class( $user, ScholarAuthor::class );

		if ( ! isset( $scholarUser ) ) {
			continue;
		}

		if ( $scholarUser->publications->isEmpty() ) {
			continue;
		}


		$scholarUsers->add( $scholarUser );
	}

	return $scholarUsers;
}
