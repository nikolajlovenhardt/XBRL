<?php
/**
 * XBRL IFRS Taxonomy Package handler
 *
 * @author Bill Seddon
 * @version 0.9
 * @Copyright (C) 2018 Lyquidity Solutions Limited
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

/**
 * Implements functions to read an IFRS taxonomy package
 */
class XBRL_IFRS_Package extends XBRL_TaxonomyPackage
{
	/**
	 * Notes about using this package instance
	 * @var string
	 */
	const notes = <<<EOT
Uses the path of the entry point in the taxonomyPackage.xml file to determine the path to use in the cache.
This should be the same as the value used in the schemaRef element in the instance document.
The 'identifier' value in the taxonomyPackage.xml file appears to be the namespace of the taxonomy.\n\n
The static array '\$defaultEntryPoints' will need to be updated each time there is a new taxonomy release
to add a new default entry point.\n\n
EOT;

	/**
	 * This will need to be updated when a new taxonomy is released
	 * @var array Indexed by publication date
	 */
	private static $defaultEntryPoints = array(
		'2016-03-31' => 'http://xbrl.ifrs.org/taxonomy/2016-03-31/combined_doc_entry_point_2016-03-31.xsd',
		'2017-03-09' => 'http://xbrl.ifrs.org/taxonomy/2017-03-09/combined_doc_entry_point_2017-03-09.xsd',
		'2018-03-16' => 'http://xbrl.ifrs.org/taxonomy/2018-03-16/combined_doc_entry_point_2018-03-16.xsd'
	);

	/**
	 * Returns true if the zip file represents a package that meets the taxonomy package specification
	 * {@inheritDoc}
	 * @see XBRL_Package::isPackage()
	 */
	public function isPackage()
	{
		if ( ! parent::isPackage() ) return false;

		// If it is a package then is an IFRS taxonomy package?
		// An IFRS package is characterised by having one or more of the
		// package entry points be the entry points in the XBRL_IFRS entry point list
		// and no instance document.
		// Check the entry points first because they are already in an array.
		foreach ( $this->getSchemaEntryPoints() as $entryPoint )
		{
			if ( XBRL::startsWith( $entryPoint, "http://xbrl.ifrs.org/" ) ) return true;
		}

		return false;
	}

	/**
	 * Workout which file is the schema file
	 * @return void
	 * @throws "tpe:schemaFileNotFound"
	 */
	protected function determineSchemaFile()
	{
		if ( ! is_null( $this->schemaFile ) ) return;

		$schemaFilesList = $this->getSchemaEntryPoints();

		if ( count( $schemaFilesList ) == 0 )
		{
			throw XBRL_TaxonomyPackageException::withError( "tpe:schemaFileNotFound", "The package does not contain a schema (.xsd) file" );
		}

		foreach ( $schemaFilesList as $schemaFile )
		{
			$actualUri = $this->getActualUri( $schemaFile );
			$content = $this->getFile( $actualUri );
			if ( $content )
			{
				$schemaNamespace = $this->getTargetNamespace( $schemaFile, $content );

				$this->setUrlMap( $schemaNamespace, $schemaFile );

				if ( isset( XBRL_IFRS_Package::$defaultEntryPoints[ $this->publicationDate ] ) &&
						XBRL_IFRS_Package::$defaultEntryPoints[ $this->publicationDate ] == $schemaFile )
				{
					$this->schemaNamespace = $schemaNamespace;
					$this->schemaFile = $schemaFile;
				}
			}
		}
	}

	/**
	 * Returns the name of the XBRL class that supports IFRS pckage contents
	 * {@inheritDoc}
	 * @see XBRL_Package::getXBRLClassname()
	 */
	public function getXBRLClassname()
	{
		return "XBRL_IFRS";
	}
}