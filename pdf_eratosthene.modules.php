<?php
/* Copyright (C) 2004-2014  Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2005-2012  Regis Houssin		<regis.houssin@inodbox.com>
 * Copyright (C) 2008		Raphael Bertrand	<raphael.bertrand@resultic.fr>
 * Copyright (C) 2010-2013	Juanjo Menent		<jmenent@2byte.es>
 * Copyright (C) 2012      	Christophe Battarel <christophe.battarel@altairis.fr>
 * Copyright (C) 2012       Cedric Salvador     <csalvador@gpcsolutions.fr>
 * Copyright (C) 2015       Marcos García       <marcosgdf@gmail.com>
 * Copyright (C) 2017       Ferran Marcet       <fmarcet@2byte.es>
 * Copyright (C) 2018-2020  Frédéric France     <frederic.france@netlogic.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 * or see https://www.gnu.org/
 */

/**
 *	\file       htdocs/core/modules/commande/doc/pdf_eratosthene.modules.php
 *	\ingroup    commande
 *	\brief      File of Class to generate PDF orders with template Eratosthene
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/commande/modules_commande.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/pdf.lib.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/companybankaccount.class.php';


/**
 *	Class to generate PDF orders with template Eratosthene
 */
class pdf_eratosthene extends ModelePDFCommandes
{
	/**
	 * @var DoliDb Database handler
	 */
	public $db;

	/**
	 * @var int The environment ID when using a multicompany module
	 */
	public $entity;

	/**
	 * @var string model name
	 */
	public $name;

	/**
	 * @var string model description (short text)
	 */
	public $description;

	/**
	 * @var int 	Save the name of generated file as the main doc when generating a doc with this template
	 */
	public $update_main_doc_field;

	/**
	 * @var string document type
	 */
	public $type;

	/**
	 * Dolibarr version of the loaded document
	 * @var string
	 */
	public $version = 'dolibarr';

	/**
	 * @var array of document table columns
	 */
	public $cols;


	/**
	 *	Constructor
	 *
	 *  @param		DoliDB		$db      Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs, $mysoc;

		// Translations
		$langs->loadLangs(array("main", "bills", "products"));

		$this->db = $db;
		$this->name = "eratosthene";
		$this->description = $langs->trans('PDFEratostheneDescription');
		$this->update_main_doc_field = 1; // Save the name of generated file as the main doc when generating a doc with this template

		// Dimension page
		$this->type = 'pdf';
		$formatarray = pdf_getFormat();
		$this->page_largeur = $formatarray['width'];
		$this->page_hauteur = $formatarray['height'];
		$this->format = array($this->page_largeur, $this->page_hauteur);
		$this->marge_gauche = getDolGlobalInt('MAIN_PDF_MARGIN_LEFT', 10);
		$this->marge_droite = getDolGlobalInt('MAIN_PDF_MARGIN_RIGHT', 10);
		$this->marge_haute = getDolGlobalInt('MAIN_PDF_MARGIN_TOP', 10);
		$this->marge_basse = getDolGlobalInt('MAIN_PDF_MARGIN_BOTTOM', 10);

		$this->option_logo = 1; // Display logo
		$this->option_tva = 1; // Manage the vat option FACTURE_TVAOPTION
		$this->option_modereg = 1; // Display payment mode
		$this->option_condreg = 1; // Display payment terms
		$this->option_multilang = 1; // Available in several languages
		$this->option_escompte = 0; // Displays if there has been a discount
		$this->option_credit_note = 0; // Support credit notes
		$this->option_freetext = 1; // Support add of a personalised text
		$this->option_draft_watermark = 1; // Support add of a watermark on drafts
		$this->watermark = '';

		// Get source company
		$this->emetteur = $mysoc;
		if (empty($this->emetteur->country_code)) {
			$this->emetteur->country_code = substr($langs->defaultlang, -2); // By default, if was not defined
		}

		// Define position of columns
		$this->posxdesc = $this->marge_gauche + 1; // used for notes ans other stuff


		$this->tabTitleHeight = 5; // default height

		//  Use new system for position of columns, view  $this->defineColumnField()

		$this->tva = array();
		$this->tva_array = array();
		$this->localtax1 = array();
		$this->localtax2 = array();
		$this->atleastoneratenotnull = 0;
		$this->atleastonediscount = 0;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 *  Function to build pdf onto disk
	 *
	 *  @param		Commande	$object				Object to generate
	 *  @param		Translate	$outputlangs		Lang output object
	 *  @param		string		$srctemplatepath	Full path of source filename for generator using a template file
	 *  @param		int			$hidedetails		Do not show line details
	 *  @param		int			$hidedesc			Do not show desc
	 *  @param		int			$hideref			Do not show ref
	 *  @return     int             			    1=OK, 0=KO
	 */
	public function write_file($object, $outputlangs, $srctemplatepath = '', $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		// phpcs:enable
		global $user, $langs, $conf, $mysoc, $db, $hookmanager, $nblines;

		dol_syslog("write_file outputlangs->defaultlang=".(is_object($outputlangs) ? $outputlangs->defaultlang : 'null'));

		if (!is_object($outputlangs)) {
			$outputlangs = $langs;
		}
		// For backward compatibility with FPDF, force output charset to ISO, because FPDF expect text to be encoded in ISO
		if (getDolGlobalInt('MAIN_USE_FPDF')) {
			$outputlangs->charset_output = 'ISO-8859-1';
		}

		// Load translation files required by the page
		$outputlangs->loadLangs(array("main", "dict", "companies", "bills", "products", "orders", "deliveries"));

		// Show Draft Watermark
		if ($object->statut == $object::STATUS_DRAFT && getDolGlobalString('COMMANDE_DRAFT_WATERMARK')) {
			$this->watermark = getDolGlobalString('COMMANDE_DRAFT_WATERMARK');
		}

		global $outputlangsbis;
		$outputlangsbis = null;
		if (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && $outputlangs->defaultlang != getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE')) {
			$outputlangsbis = new Translate('', $conf);
			$outputlangsbis->setDefaultLang(getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE'));
			$outputlangsbis->loadLangs(array("main", "dict", "companies", "bills", "products", "orders", "deliveries"));
		}

		$nblines = (is_array($object->lines) ? count($object->lines) : 0);

		$hidetop = 0;
		if (getDolGlobalString('MAIN_PDF_DISABLE_COL_HEAD_TITLE')) {
			$hidetop = getDolGlobalString('MAIN_PDF_DISABLE_COL_HEAD_TITLE');
		}

		// Loop on each lines to detect if there is at least one image to show
		$realpatharray = array();
		$this->atleastonephoto = false;
		if (getDolGlobalInt('MAIN_GENERATE_ORDERS_WITH_PICTURE')) {
			$objphoto = new Product($this->db);

			for ($i = 0; $i < $nblines; $i++) {
				if (empty($object->lines[$i]->fk_product)) {
					continue;
				}

				$objphoto->fetch($object->lines[$i]->fk_product);
				//var_dump($objphoto->ref);exit;
				if (getDolGlobalInt('PRODUCT_USE_OLD_PATH_FOR_PHOTO')) {
					$pdir[0] = get_exdir($objphoto->id, 2, 0, 0, $objphoto, 'product').$objphoto->id."/photos/";
					$pdir[1] = get_exdir(0, 0, 0, 0, $objphoto, 'product').dol_sanitizeFileName($objphoto->ref).'/';
				} else {
					$pdir[0] = get_exdir(0, 0, 0, 0, $objphoto, 'product'); // default
					$pdir[1] = get_exdir($objphoto->id, 2, 0, 0, $objphoto, 'product').$objphoto->id."/photos/"; // alternative
				}

				$arephoto = false;
				foreach ($pdir as $midir) {
					if (!$arephoto) {
						if ($conf->entity != $objphoto->entity) {
							$dir = $conf->product->multidir_output[$objphoto->entity].'/'.$midir; //Check repertories of current entities
						} else {
							$dir = $conf->product->dir_output.'/'.$midir; //Check repertory of the current product
						}

						foreach ($objphoto->liste_photos($dir, 1) as $key => $obj) {
							if (!getDolGlobalInt('CAT_HIGH_QUALITY_IMAGES')) {		// If CAT_HIGH_QUALITY_IMAGES not defined, we use thumb if defined and then original photo
								if ($obj['photo_vignette']) {
									$filename = $obj['photo_vignette'];
								} else {
									$filename = $obj['photo'];
								}
							} else {
								$filename = $obj['photo'];
							}

							$realpath = $dir.$filename;
							$arephoto = true;
							$this->atleastonephoto = true;
						}
					}
				}

				if ($realpath && $arephoto) {
					$realpatharray[$i] = $realpath;
				}
			}
		}



		if (getMultidirOutput($object)) {
			$object->fetch_thirdparty();

			$deja_regle = 0;

			// Definition of $dir and $file
			if ($object->specimen) {
				$dir = getMultidirOutput($object);
				$file = $dir."/SPECIMEN.pdf";
			} else {
				$objectref = dol_sanitizeFileName($object->ref);
				$dir = getMultidirOutput($object)."/".$objectref;
				$file = $dir."/".$objectref."_AR.pdf";
			}

			if (!file_exists($dir)) {
				if (dol_mkdir($dir) < 0) {
					$this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
					return 0;
				}
			}

			if (file_exists($dir)) {
				// Add pdfgeneration hook
				if (!is_object($hookmanager)) {
					include_once DOL_DOCUMENT_ROOT.'/core/class/hookmanager.class.php';
					$hookmanager = new HookManager($this->db);
				}
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters = array('file'=>$file, 'object'=>$object, 'outputlangs'=>$outputlangs);
				global $action;
				$reshook = $hookmanager->executeHooks('beforePDFCreation', $parameters, $object, $action); // Note that $action and $object may have been modified by some hooks

				// Set nblines with the new lines content after hook
				$nblines = (is_array($object->lines) ? count($object->lines) : 0);

				// Create pdf instance
				$pdf = pdf_getInstance($this->format);
				$default_font_size = pdf_getPDFFontSize($outputlangs); // Must be after pdf_getInstance
				$pdf->SetAutoPageBreak(1, 0);

				$heightforinfotot = 22; // Height reserved to output the info and total part
				$heightforfreetext = (isset($conf->global->MAIN_PDF_FREETEXT_HEIGHT) ? $conf->global->MAIN_PDF_FREETEXT_HEIGHT : 5); // Height reserved to output the free text on last page
				$heightforfooter = $this->marge_basse + (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS') ? 12 : 22); // Height reserved to output the footer (value include bottom margin)

				if (class_exists('TCPDF')) {
					$pdf->setPrintHeader(false);
					$pdf->setPrintFooter(false);
				}
				$pdf->SetFont(pdf_getPDFFont($outputlangs));
				// Set path to the background PDF File
				if (getDolGlobalString('MAIN_ADD_PDF_BACKGROUND')) {
					$logodir = $conf->mycompany->dir_output;
					if (!empty($conf->mycompany->multidir_output[$object->entity])) {
						$logodir = $conf->mycompany->multidir_output[$object->entity];
					}
					$pagecount = $pdf->setSourceFile($logodir.'/'.getDolGlobalString('MAIN_ADD_PDF_BACKGROUND'));
					$tplidx = $pdf->importPage(1);
				}

				$pdf->Open();
				$pagenb = 0;
				$pdf->SetDrawColor(128, 128, 128);

				$pdf->SetTitle($outputlangs->convToOutputCharset($object->ref));
				$pdf->SetSubject($outputlangs->transnoentities("PdfOrderTitle"));
				$pdf->SetCreator("Dolibarr ".DOL_VERSION);
				$pdf->SetAuthor($outputlangs->convToOutputCharset($user->getFullName($outputlangs)));
				$pdf->SetKeyWords($outputlangs->convToOutputCharset($object->ref)." ".$outputlangs->transnoentities("PdfOrderTitle")." ".$outputlangs->convToOutputCharset($object->thirdparty->name));
				if (getDolGlobalString('MAIN_DISABLE_PDF_COMPRESSION')) {
					$pdf->SetCompression(false);
				}

				$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite); // Left, Top, Right

				// Set $this->atleastonediscount if you have at least one discount
				for ($i = 0; $i < $nblines; $i++) {
					if ($object->lines[$i]->remise_percent) {
						$this->atleastonediscount++;
					}
				}


				// New page
				$pdf->AddPage();
				if (!empty($tplidx)) {
					$pdf->useTemplate($tplidx);
				}
				$pagenb++;
				$top_shift = $this->_pagehead($pdf, $object, 1, $outputlangs, $outputlangsbis);
				$pdf->SetFont('', '', $default_font_size - 1);
				$pdf->MultiCell(0, 3, ''); // Set interline to 3
				$pdf->SetTextColor(0, 0, 0);

				// Calculer tab_top_newpage AVANT d'ajouter le bloc des dates (qui n'existe que sur page 1)
				$tab_top_newpage = (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD') ? 42 + $top_shift : 10);

				// BLOC Dates limites (prepa_cde et delai_liv) - Uniquement sur la première page
				// Récupérer les extrafields de la commande
				$prepa_cde = '';
				$delai_liv = '';
				if (!empty($object->array_options['options_prepa_cde'])) {
					$prepa_cde = $object->array_options['options_prepa_cde'];
					// Si c'est une date, la formater
					if (is_numeric($prepa_cde) && $prepa_cde > 0) {
						$prepa_cde = dol_print_date($prepa_cde, 'day', false, $outputlangs);
					}
				}
				if (!empty($object->array_options['options_delai_liv'])) {
					$delai_liv = $object->array_options['options_delai_liv'];
				}

				// Créer le tableau HTML pour les dates limites (sans bordures internes)
				$html = '<table width="100%" border="0" cellpadding="4" cellspacing="0">';
				$html .= '<tr>';
				$html .= '<td width="60%" style="color: #000060;"><b>DATE LIMITE DE MODIFICATION DE COMMANDE *</b></td>';
				$html .= '<td width="40%" style="color: #000000;">: ' . $prepa_cde . '</td>';
				$html .= '</tr>';
				$html .= '<tr>';
				$html .= '<td width="60%" style="color: #000060;"><b>DÉLAI ESTIMATIF DE LIVRAISON / MISE A DISPOSITION</b></td>';
				$html .= '<td width="40%" style="color: #000000;">: ' . $delai_liv . '</td>';
				$html .= '</tr>';
				$html .= '</table>';

				// Position après le header (augmenté pour descendre le bloc)
				// Utilise une position fixe pour garantir un rendu constant
				// Calculé : position blocs adresses (32) + hauteur cadre (40) + espacement (2+5) = 79mm
				// On ajoute $top_shift car les blocs d'adresses l'utilisent aussi
				$posy_dates = 79 + $top_shift;
				$pdf->SetFont('', '', $default_font_size);
				$tableWidth = $this->page_largeur - $this->marge_gauche - $this->marge_droite;

				// Capturer la position avant d'écrire pour dessiner le cadre
				$table_x = $this->marge_gauche;
				$table_y = $posy_dates;

				$pdf->writeHTMLCell($tableWidth, 0, $this->marge_gauche, $posy_dates, $html, 0, 1, false, true, 'L', true);

				// Dessiner le cadre externe avec le même style que les autres tableaux
				$table_height = $pdf->GetY() - $table_y;
				$pdf->SetDrawColor(128, 128, 128);
				$pdf->Rect($table_x, $table_y, $tableWidth, $table_height);

				// Ajouter le texte de disclaimer en dessous
				$posy_after_table = $pdf->GetY() + 1;
				$pdf->SetFont('', '', $default_font_size - 1);
				$pdf->SetTextColor(0, 0, 0);
				$pdf->SetXY($this->marge_gauche, $posy_after_table);
				$pdf->MultiCell($tableWidth, 3, '*Des frais peuvent s\'appliquer en cas de modification de la commande après cette date', 0, 'L');

				// Calculer l'espace utilisé par le bloc des dates pour ajuster tab_top (uniquement pour page 1)
				// Hauteur fixe pour avoir un rendu constant sur tous les PDF
				$dates_block_height = 15; // Hauteur fixe en mm pour les 2 lignes de dates + disclaimer
				$top_shift += $dates_block_height;


				$tab_top = 90 + $top_shift;

				$tab_height = $this->page_hauteur - $tab_top - $heightforfooter - $heightforfreetext - $heightforinfotot;

				$nexY = $tab_top - 1;

				// Incoterm
				$height_incoterms = 0;
				if (isModEnabled('incoterm')) {
					$desc_incoterms = $object->getIncotermsForPDF();
					if ($desc_incoterms) {
						$tab_top -= 2;

						$pdf->SetFont('', '', $default_font_size - 1);
						$pdf->writeHTMLCell(190, 3, $this->posxdesc - 1, $tab_top - 1, dol_htmlentitiesbr($desc_incoterms), 0, 1);
						$nexY = max($pdf->GetY(), $nexY);
						$height_incoterms = $nexY - $tab_top;

						// Rect takes a length in 3rd parameter
						$pdf->SetDrawColor(192, 192, 192);
						$pdf->Rect($this->marge_gauche, $tab_top - 1, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $height_incoterms + 1);

						$tab_top = $nexY + 6;
					}
				}

				// Display notes
				$notetoshow = empty($object->note_public) ? '' : $object->note_public;
				if (getDolGlobalString('MAIN_ADD_SALE_REP_SIGNATURE_IN_NOTE')) {
					// Get first sale rep
					if (is_object($object->thirdparty)) {
						$salereparray = $object->thirdparty->getSalesRepresentatives($user);
						$salerepobj = new User($this->db);
						$salerepobj->fetch($salereparray[0]['id']);
						if (!empty($salerepobj->signature)) {
							$notetoshow = dol_concatdesc($notetoshow, $salerepobj->signature);
						}
					}
				}
				// Extrafields in note
				$extranote = $this->getExtrafieldsInHtml($object, $outputlangs);
				if (!empty($extranote)) {
					$notetoshow = dol_concatdesc($notetoshow, $extranote);
				}

				$pagenb = $pdf->getPage();
				if ($notetoshow) {
					$tab_top -= 2;

					$tab_width = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
					$pageposbeforenote = $pagenb;

					$substitutionarray = pdf_getSubstitutionArray($outputlangs, null, $object);
					complete_substitutions_array($substitutionarray, $outputlangs, $object);
					$notetoshow = make_substitutions($notetoshow, $substitutionarray, $outputlangs);
					$notetoshow = convertBackOfficeMediasLinksToPublicLinks($notetoshow);

					$pdf->startTransaction();

					$pdf->SetFont('', '', $default_font_size - 1);
					$pdf->writeHTMLCell(190, 3, $this->posxdesc - 1, $tab_top, dol_htmlentitiesbr($notetoshow), 0, 1);
					// Description
					$pageposafternote = $pdf->getPage();
					$posyafter = $pdf->GetY();

					if ($pageposafternote > $pageposbeforenote) {
						$pdf->rollbackTransaction(true);

						// prepare pages to receive notes
						while ($pagenb < $pageposafternote) {
							$pdf->AddPage();
							$pagenb++;
							if (!empty($tplidx)) {
								$pdf->useTemplate($tplidx);
							}
							if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
								$this->_pagehead($pdf, $object, 0, $outputlangs);
							}
							// $this->_pagefoot($pdf,$object,$outputlangs,1);
							$pdf->setTopMargin($tab_top_newpage);
							// The only function to edit the bottom margin of current page to set it.
							$pdf->setPageOrientation('', 1, $heightforfooter + $heightforfreetext);
						}

						// back to start
						$pdf->setPage($pageposbeforenote);
						$pdf->setPageOrientation('', 1, $heightforfooter + $heightforfreetext);
						$pdf->SetFont('', '', $default_font_size - 1);
						$pdf->writeHTMLCell(190, 3, $this->posxdesc - 1, $tab_top, dol_htmlentitiesbr($notetoshow), 0, 1);
						$pageposafternote = $pdf->getPage();

						$posyafter = $pdf->GetY();

						if ($posyafter > ($this->page_hauteur - ($heightforfooter + $heightforfreetext + 20))) {	// There is no space left for total+free text
							$pdf->AddPage('', '', true);
							$pagenb++;
							$pageposafternote++;
							$pdf->setPage($pageposafternote);
							$pdf->setTopMargin($tab_top_newpage);
							// The only function to edit the bottom margin of current page to set it.
							$pdf->setPageOrientation('', 1, $heightforfooter + $heightforfreetext);
							//$posyafter = $tab_top_newpage;
						}


						// apply note frame to previous pages
						$i = $pageposbeforenote;
						while ($i < $pageposafternote) {
							$pdf->setPage($i);


							$pdf->SetDrawColor(128, 128, 128);
							// Draw note frame
							if ($i > $pageposbeforenote) {
								$height_note = $this->page_hauteur - ($tab_top_newpage + $heightforfooter);
								$pdf->Rect($this->marge_gauche, $tab_top_newpage - 1, $tab_width, $height_note + 1);
							} else {
								$height_note = $this->page_hauteur - ($tab_top + $heightforfooter);
								$pdf->Rect($this->marge_gauche, $tab_top - 1, $tab_width, $height_note + 1);
							}

							// Add footer
							$pdf->setPageOrientation('', 1, 0); // The only function to edit the bottom margin of current page to set it.
							$this->_pagefoot($pdf, $object, $outputlangs, 1);

							$i++;
						}

						// apply note frame to last page
						$pdf->setPage($pageposafternote);
						if (!empty($tplidx)) {
							$pdf->useTemplate($tplidx);
						}
						if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
							$this->_pagehead($pdf, $object, 0, $outputlangs);
						}
						$height_note = $posyafter - $tab_top_newpage;
						$pdf->Rect($this->marge_gauche, $tab_top_newpage - 1, $tab_width, $height_note + 1);
					} else {
						// No pagebreak
						$pdf->commitTransaction();
						$posyafter = $pdf->GetY();
						$height_note = $posyafter - $tab_top;
						$pdf->Rect($this->marge_gauche, $tab_top - 1, $tab_width, $height_note + 1);


						if ($posyafter > ($this->page_hauteur - ($heightforfooter + $heightforfreetext + 20))) {
							// not enough space, need to add page
							$pdf->AddPage('', '', true);
							$pagenb++;
							$pageposafternote++;
							$pdf->setPage($pageposafternote);
							if (!empty($tplidx)) {
								$pdf->useTemplate($tplidx);
							}
							if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
								$this->_pagehead($pdf, $object, 0, $outputlangs);
							}

							$posyafter = $tab_top_newpage;
						}
					}

					$tab_height = $tab_height - $height_note;
					$tab_top = $posyafter + 6;
				} else {
					$height_note = 0;
				}


				// Use new auto column system
				$this->prepareArrayColumnField($object, $outputlangs, $hidedetails, $hidedesc, $hideref);

				// Table simulation to know the height of the title line
				$pdf->startTransaction();
				$this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs, $hidetop);
				$pdf->rollbackTransaction(true);

				$nexY = $tab_top + $this->tabTitleHeight;

				// Initialize subtotal tracking if enabled
				$showSubtotals = !empty($object->array_options['options_sous_total']);
				$currentSubtotal = 0;
				$hasCurrentSection = false;
				$firstTitleEncountered = false;

				// Loop on each lines
				$pageposbeforeprintlines = $pdf->getPage();
				$pagenb = $pageposbeforeprintlines;
				for ($i = 0; $i < $nblines; $i++) {
					$curY = $nexY;
					$pdf->SetFont('', '', $default_font_size - 1); // Into loop to work with multipage
					$pdf->SetTextColor(0, 0, 0);

					// Define size of image if we need it
					$imglinesize = array();
					if (!empty($realpatharray[$i])) {
						$imglinesize = pdf_getSizeForImage($realpatharray[$i]);
					}

					$pdf->setTopMargin($tab_top_newpage);
					$pdf->setPageOrientation('', 1, $heightforfooter + $heightforfreetext + $heightforinfotot); // The only function to edit the bottom margin of current page to set it.
					$pageposbefore = $pdf->getPage();


					$showpricebeforepagebreak = 1;
					$posYAfterImage = 0;
					$posYAfterDescription = 0;

					if ($this->getColumnStatus('photo')) {
						// We start with Photo of product line
						if (isset($imglinesize['width']) && isset($imglinesize['height']) && ($curY + $imglinesize['height']) > ($this->page_hauteur - ($heightforfooter + $heightforfreetext + $heightforinfotot))) {	// If photo too high, we moved completely on new page
							$pdf->AddPage('', '', true);
							if (!empty($tplidx)) {
								$pdf->useTemplate($tplidx);
							}
							$pdf->setPage($pageposbefore + 1);

							$curY = $tab_top_newpage;

							// Allows data in the first page if description is long enough to break in multiples pages
							if (getDolGlobalInt('MAIN_PDF_DATA_ON_FIRST_PAGE')) {
								$showpricebeforepagebreak = 1;
							} else {
								$showpricebeforepagebreak = 0;
							}
						}

						if (!empty($this->cols['photo']) && isset($imglinesize['width']) && isset($imglinesize['height'])) {
							$pdf->Image($realpatharray[$i], $this->getColumnContentXStart('photo'), $curY + 1, $imglinesize['width'], $imglinesize['height'], '', '', '', 2, 300); // Use 300 dpi
							// $pdf->Image does not increase value return by getY, so we save it manually
							$posYAfterImage = $curY + $imglinesize['height'];
						}
					}

					// Description of product line
					if ($this->getColumnStatus('desc')) {
						// Check if this is the special "Libelle_Cde" service (ID 361) used as title
						$isTitleService = (isset($object->lines[$i]->fk_product) && $object->lines[$i]->fk_product == 361);

						// Special handling for title service: display description on full width
						if ($isTitleService) {
							// Handle subtotal display before the new title (except the first one)
							if ($showSubtotals && $firstTitleEncountered && $hasCurrentSection) {
								// Display subtotal as a simple single line (label + amount concatenated)
								$pdf->SetFont('', 'B', $default_font_size - 1);
								$pdf->SetFillColor(240, 240, 240);

								$subtotalLabel = $outputlangs->trans('Subtotal');
								$subtotalAmount = price($currentSubtotal, 0, $outputlangs);

								// Create a single line with label and amount
								$fullWidth = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
								$subtotalText = $subtotalLabel . '  ' . $subtotalAmount;

								$pdf->SetXY($this->marge_gauche, $curY);
								$pdf->Cell($fullWidth, 4, $subtotalText, 0, 1, 'R', 1);
								$curY = $pdf->GetY() + 1;
								$nexY = $curY;

								// Reset for next section
								$currentSubtotal = 0;
								$hasCurrentSection = false;
							}

							$firstTitleEncountered = true;
							$pdf->SetFont('', 'B', $default_font_size);
							$pdf->SetFillColor(230, 230, 230);
							$pdf->SetTextColor(0, 0, 60);
							$fullWidth = $this->page_largeur - $this->marge_gauche - $this->marge_droite;

							// Use dol_htmlentitiesbr to properly handle HTML entities and line breaks
							$pdf->writeHTMLCell($fullWidth, 0, $this->marge_gauche, $curY, dol_htmlentitiesbr($object->lines[$i]->desc), 0, 1, true, true, 'L', true);
							$curY = $pdf->GetY();
							$pdf->SetTextColor(0, 0, 0);
						} else {
							// Normal handling for products and regular services
							// Modify description to include detail extrafield in 2 columns format
							// Only for products (product_type = 0), not for services (product_type = 1)
							$isProduct = (isset($object->lines[$i]->product_type) && $object->lines[$i]->product_type == 0);

							$originalDesc = $object->lines[$i]->desc;
							$detail = '';
							if (!empty($object->lines[$i]->array_options['options_detail'])) {
								$detail = $object->lines[$i]->array_options['options_detail'];
							}

							// Create a 2-column table with description and detail (only for products)
							// Use dol_htmlentitiesbr to properly handle HTML entities and line breaks
							if ($isProduct && (!empty($originalDesc) || !empty($detail))) {
								$object->lines[$i]->desc = '<table width="100%" border="0" cellpadding="0" cellspacing="0"><tr>';
								$object->lines[$i]->desc .= '<td width="50%" valign="top">' . dol_htmlentitiesbr($originalDesc) . '</td>';
								$object->lines[$i]->desc .= '<td width="50%" valign="top">' . dol_htmlentitiesbr($detail) . '</td>';
								$object->lines[$i]->desc .= '</tr></table>';
							}

							// Add eco-participation if present
							if (!empty($object->lines[$i]->array_options['options_montant_ecotaxe'])) {
								$ecotaxe_value = price2num($object->lines[$i]->array_options['options_montant_ecotaxe']);
								$ecotaxe = price($ecotaxe_value, 0, $outputlangs, 1, -1, -1, $conf->currency);
								$prefix = !empty(trim(strip_tags($object->lines[$i]->desc))) ? '<br>' : '';
								$object->lines[$i]->desc .= $prefix . '<i>Éco-participation : ' . $ecotaxe . '</i>';
							}

							$pdf->startTransaction();

						$this->printColDescContent($pdf, $curY, 'desc', $object, $i, $outputlangs, $hideref, $hidedesc);
						$pageposafter = $pdf->getPage();

						if ($pageposafter > $pageposbefore) {	// There is a pagebreak
							$pdf->rollbackTransaction(true);
							$pageposafter = $pageposbefore;
							//print $pageposafter.'-'.$pageposbefore;exit;
							$pdf->setPageOrientation('', 1, $heightforfooter); // The only function to edit the bottom margin of current page to set it.

							$this->printColDescContent($pdf, $curY, 'desc', $object, $i, $outputlangs, $hideref, $hidedesc);
							$pageposafter = $pdf->getPage();
							$posyafter = $pdf->GetY();
							if ($posyafter > ($this->page_hauteur - ($heightforfooter + $heightforfreetext + $heightforinfotot))) {	// There is no space left for total+free text
								if ($i == ($nblines - 1)) {	// No more lines, and no space left to show total, so we create a new page
									$pdf->AddPage('', '', true);
									if (!empty($tplidx)) {
										$pdf->useTemplate($tplidx);
									}
									$pdf->setPage($pageposafter + 1);
								}
							} else {
								// We found a page break
								// Allows data in the first page if description is long enough to break in multiples pages
								if (getDolGlobalInt('MAIN_PDF_DATA_ON_FIRST_PAGE')) {
									$showpricebeforepagebreak = 1;
								} else {
									$showpricebeforepagebreak = 0;
								}
							}
						} else {	// No pagebreak
							$pdf->commitTransaction();
						}
						$posYAfterDescription = $pdf->GetY();
						}
					}


					$nexY = max($pdf->GetY(), $posYAfterImage);


					$pageposafter = $pdf->getPage();

					$pdf->setPage($pageposbefore);
					$pdf->setTopMargin($this->marge_haute);
					$pdf->setPageOrientation('', 1, 0); // The only function to edit the bottom margin of current page to set it.

					// We suppose that a too long description or photo were moved completely on next page
					if ($pageposafter > $pageposbefore && empty($showpricebeforepagebreak)) {
						$pdf->setPage($pageposafter);
						$curY = $tab_top_newpage;
					}

					$pdf->SetFont('', '', $default_font_size - 1); // We reposition the default font

					// Check if this is the special "Libelle_Cde" service (ID 361) used as title
					$isTitleService = (isset($object->lines[$i]->fk_product) && $object->lines[$i]->fk_product == 361);

					// VAT Rate
					if ($this->getColumnStatus('vat')) {
						$vat_rate = pdf_getlinevatrate($object, $i, $outputlangs, $hidedetails);
						$this->printStdColumnContent($pdf, $curY, 'vat', $vat_rate);
						$nexY = max($pdf->GetY(), $nexY);
					}

					// Unit price before discount (hidden for title service)
					if ($this->getColumnStatus('subprice') && !$isTitleService) {
						$up_excl_tax = pdf_getlineupexcltax($object, $i, $outputlangs, $hidedetails);
						$this->printStdColumnContent($pdf, $curY, 'subprice', $up_excl_tax);
						$nexY = max($pdf->GetY(), $nexY);
					}

					// Quantity with unit (hidden for title service)
					if ($this->getColumnStatus('qty') && !$isTitleService) {
						$qty = pdf_getlineqty($object, $i, $outputlangs, $hidedetails);
						$unit = pdf_getlineunit($object, $i, $outputlangs, $hidedetails, $hookmanager);
						// Add unit to quantity if it exists
						$qtyWithUnit = $qty;
						if (!empty($unit)) {
							$qtyWithUnit .= ' ' . $unit;
						}
						$this->printStdColumnContent($pdf, $curY, 'qty', $qtyWithUnit);
						$nexY = max($pdf->GetY(), $nexY);
					}


					// Unit (hidden now, unit is shown with qty)
					if ($this->getColumnStatus('unit')) {
						$unit = pdf_getlineunit($object, $i, $outputlangs, $hidedetails, $hookmanager);
						$this->printStdColumnContent($pdf, $curY, 'unit', $unit);
						$nexY = max($pdf->GetY(), $nexY);
					}

					// Discount on line
					if ($this->getColumnStatus('discount') && $object->lines[$i]->remise_percent) {
						$remise_percent = pdf_getlineremisepercent($object, $i, $outputlangs, $hidedetails);
						$this->printStdColumnContent($pdf, $curY, 'discount', $remise_percent);
						$nexY = max($pdf->GetY(), $nexY);
					}

					// Total excl tax line (HT) (hidden for title service)
					if ($this->getColumnStatus('totalexcltax') && !$isTitleService) {
						$total_excl_tax = pdf_getlinetotalexcltax($object, $i, $outputlangs, $hidedetails);
						$this->printStdColumnContent($pdf, $curY, 'totalexcltax', $total_excl_tax);
						$nexY = max($pdf->GetY(), $nexY);

						// Accumulate for subtotal if enabled
						if ($showSubtotals) {
							$currentSubtotal += $object->lines[$i]->total_ht;
							$hasCurrentSection = true;
						}
					}

					// Total with tax line (TTC)
					if ($this->getColumnStatus('totalincltax')) {
						$total_incl_tax = pdf_getlinetotalwithtax($object, $i, $outputlangs, $hidedetails);
						$this->printStdColumnContent($pdf, $curY, 'totalincltax', $total_incl_tax);
						$nexY = max($pdf->GetY(), $nexY);
					}

					// Extrafields (exclude 'options_detail' as it is displayed in custom description)
					if (!empty($object->lines[$i]->array_options)) {
						foreach ($object->lines[$i]->array_options as $extrafieldColKey => $extrafieldValue) {
							// Skip detail as it is displayed in the custom description section
							if ($extrafieldColKey === 'options_detail') {
								continue;
							}
							if ($this->getColumnStatus($extrafieldColKey)) {
								$extrafieldValue = $this->getExtrafieldContent($object->lines[$i], $extrafieldColKey, $outputlangs);
								$this->printStdColumnContent($pdf, $curY, $extrafieldColKey, $extrafieldValue);
								$nexY = max($pdf->GetY(), $nexY);
							}
						}
					}

					$parameters = array(
						'object' => $object,
						'i' => $i,
						'pdf' =>& $pdf,
						'curY' =>& $curY,
						'nexY' =>& $nexY,
						'outputlangs' => $outputlangs,
						'hidedetails' => $hidedetails
					);
					$reshook = $hookmanager->executeHooks('printPDFline', $parameters, $this); // Note that $object may have been modified by hook


					// Collection of totals by value of vat in $this->tva["rate"] = total_tva
					if (isModEnabled("multicurrency") && $object->multicurrency_tx != 1) {
						$tvaligne = $object->lines[$i]->multicurrency_total_tva;
					} else {
						$tvaligne = $object->lines[$i]->total_tva;
					}

					$localtax1ligne = $object->lines[$i]->total_localtax1;
					$localtax2ligne = $object->lines[$i]->total_localtax2;
					$localtax1_rate = $object->lines[$i]->localtax1_tx;
					$localtax2_rate = $object->lines[$i]->localtax2_tx;
					$localtax1_type = $object->lines[$i]->localtax1_type;
					$localtax2_type = $object->lines[$i]->localtax2_type;

					$vatrate = (string) $object->lines[$i]->tva_tx;

					// Retrieve type from database for backward compatibility with old records
					if ((!isset($localtax1_type) || $localtax1_type == '' || !isset($localtax2_type) || $localtax2_type == '') // if tax type not defined
					&& (!empty($localtax1_rate) || !empty($localtax2_rate))) { // and there is local tax
						$localtaxtmp_array = getLocalTaxesFromRate($vatrate, 0, $object->thirdparty, $mysoc);
						$localtax1_type = isset($localtaxtmp_array[0]) ? $localtaxtmp_array[0] : '';
						$localtax2_type = isset($localtaxtmp_array[2]) ? $localtaxtmp_array[2] : '';
					}

					// retrieve global local tax
					if ($localtax1_type && $localtax1ligne != 0) {
						if (empty($this->localtax1[$localtax1_type][$localtax1_rate])) {
							$this->localtax1[$localtax1_type][$localtax1_rate] = $localtax1ligne;
						} else {
							$this->localtax1[$localtax1_type][$localtax1_rate] += $localtax1ligne;
						}
					}
					if ($localtax2_type && $localtax2ligne != 0) {
						if (empty($this->localtax2[$localtax2_type][$localtax2_rate])) {
							$this->localtax2[$localtax2_type][$localtax2_rate] = $localtax2ligne;
						} else {
							$this->localtax2[$localtax2_type][$localtax2_rate] += $localtax2ligne;
						}
					}

					if (($object->lines[$i]->info_bits & 0x01) == 0x01) {
						$vatrate .= '*';
					}

					// Fill $this->tva and $this->tva_array
					if (!isset($this->tva[$vatrate])) {
						$this->tva[$vatrate] = 0;
					}
					$this->tva[$vatrate] += $tvaligne;
					$vatcode = $object->lines[$i]->vat_src_code;
					if (empty($this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')]['amount'])) {
						$this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')]['amount'] = 0;
					}
					$this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')] = array('vatrate'=>$vatrate, 'vatcode'=>$vatcode, 'amount'=> $this->tva_array[$vatrate.($vatcode ? ' ('.$vatcode.')' : '')]['amount'] + $tvaligne);

					// Add line
					if (getDolGlobalInt('MAIN_PDF_DASH_BETWEEN_LINES') && $i < ($nblines - 1)) {
						$pdf->setPage($pageposafter);
						$pdf->SetLineStyle(array('dash'=>'1,1', 'color'=>array(80, 80, 80)));
						//$pdf->SetDrawColor(190,190,200);
						$pdf->line($this->marge_gauche, $nexY, $this->page_largeur - $this->marge_droite, $nexY);
						$pdf->SetLineStyle(array('dash'=>0));
					}


					// Detect if some page were added automatically and output _tableau for past pages
					while ($pagenb < $pageposafter) {
						$pdf->setPage($pagenb);
						if ($pagenb == $pageposbeforeprintlines) {
							$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforfooter, 0, $outputlangs, $hidetop, 1, $object->multicurrency_code, $outputlangsbis);
						} else {
							$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 1, 1, $object->multicurrency_code, $outputlangsbis);
						}
						$this->_pagefoot($pdf, $object, $outputlangs, 1);
						$pagenb++;
						$pdf->setPage($pagenb);
						$pdf->setPageOrientation('', 1, 0); // The only function to edit the bottom margin of current page to set it.
						if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
							$this->_pagehead($pdf, $object, 0, $outputlangs);
						}
						if (!empty($tplidx)) {
							$pdf->useTemplate($tplidx);
						}
					}
					if (isset($object->lines[$i + 1]->pagebreak) && $object->lines[$i + 1]->pagebreak) {
						if ($pagenb == $pageposafter) {
							$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforfooter, 0, $outputlangs, $hidetop, 1, $object->multicurrency_code, $outputlangsbis);
						} else {
							$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforfooter, 0, $outputlangs, 1, 1, $object->multicurrency_code, $outputlangsbis);
						}
						$this->_pagefoot($pdf, $object, $outputlangs, 1);
						// New page
						$pdf->AddPage();
						if (!empty($tplidx)) {
							$pdf->useTemplate($tplidx);
						}
						$pagenb++;
						if (!getDolGlobalInt('MAIN_PDF_DONOTREPEAT_HEAD')) {
							$this->_pagehead($pdf, $object, 0, $outputlangs);
						}
					}
				}

				// Display final subtotal if enabled and there's a current section
				if ($showSubtotals && $hasCurrentSection) {
					$curY = $nexY;
					$pdf->SetFont('', 'B', $default_font_size - 1);
					$pdf->SetFillColor(240, 240, 240);

					// Display subtotal as a simple single line (label + amount concatenated)
					$subtotalLabel = $outputlangs->trans('Subtotal');
					$subtotalAmount = price($currentSubtotal, 0, $outputlangs);

					// Create a single line with label and amount
					$fullWidth = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
					$subtotalText = $subtotalLabel . '  ' . $subtotalAmount;

					$pdf->SetXY($this->marge_gauche, $curY);
					$pdf->Cell($fullWidth, 4, $subtotalText, 0, 1, 'R', 1);

					$nexY = $pdf->GetY() + 1;
				}

				// Show square
				if ($pagenb == $pageposbeforeprintlines) {
					$this->_tableau($pdf, $tab_top, $this->page_hauteur - $tab_top - $heightforinfotot - $heightforfreetext - $heightforfooter, 0, $outputlangs, $hidetop, 0, $object->multicurrency_code, $outputlangsbis);
				} else {
					$this->_tableau($pdf, $tab_top_newpage, $this->page_hauteur - $tab_top_newpage - $heightforinfotot - $heightforfreetext - $heightforfooter, 0, $outputlangs, 1, 0, $object->multicurrency_code, $outputlangsbis);
				}
				$bottomlasttab = $this->page_hauteur - $heightforinfotot - $heightforfreetext - $heightforfooter + 1;

				// Display infos area
				$posy = $this->drawInfoTable($pdf, $object, $bottomlasttab, $outputlangs);

				// Display total zone
				$posy = $this->drawTotalTable($pdf, $object, $deja_regle, $bottomlasttab, $outputlangs);

				// Display payment area
				/*
				if ($deja_regle)
				{
					$posy=$this->drawPaymentsTable($pdf, $object, $posy, $outputlangs);
				}
				*/

				// Pagefoot
				$this->_pagefoot($pdf, $object, $outputlangs);

				// Page fiche de renseignement client (si extrafield info_client coché)
				if (!empty($object->array_options['options_info_client'])) {
					$this->_drawClientInfoPage($pdf, $object, $outputlangs);
				}

				if (method_exists($pdf, 'AliasNbPages')) {
					$pdf->AliasNbPages();
				}

				$pdf->Close();

				$pdf->Output($file, 'F');

				// Add pdfgeneration hook
				$hookmanager->initHooks(array('pdfgeneration'));
				$parameters = array('file'=>$file, 'object'=>$object, 'outputlangs'=>$outputlangs);
				global $action;
				$reshook = $hookmanager->executeHooks('afterPDFCreation', $parameters, $this, $action); // Note that $action and $object may have been modified by some hooks
				if ($reshook < 0) {
					$this->error = $hookmanager->error;
					$this->errors = $hookmanager->errors;
				}

				dolChmod($file);

				$this->result = array('fullpath'=>$file);

				return 1; // No error
			} else {
				$this->error = $langs->transnoentities("ErrorCanNotCreateDir", $dir);
				return 0;
			}
		} else {
			$this->error = $langs->transnoentities("ErrorConstantNotDefined", "COMMANDE_OUTPUTDIR");
			return 0;
		}
	}

	/**
	 *  Show payments table
	 *
	 *  @param	TCPDF		$pdf     		Object PDF
	 *  @param  Commande	$object			Object order
	 *	@param	int			$posy			Position y in PDF
	 *	@param	Translate	$outputlangs	Object langs for output
	 *	@return int							Return integer <0 if KO, >0 if OK
	 */
	protected function drawPaymentsTable(&$pdf, $object, $posy, $outputlangs)
	{
		return 0;
	}

	/**
	 *   Show miscellaneous information (payment mode, payment term, ...)
	 *
	 *   @param		TCPDF		$pdf     		Object PDF
	 *   @param		Commande	$object			Object to show
	 *   @param		int			$posy			Y
	 *   @param		Translate	$outputlangs	Langs object
	 *   @return	int							Pos y
	 */
	protected function drawInfoTable(&$pdf, $object, $posy, $outputlangs)
	{
		global $conf, $mysoc;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		$pdf->SetFont('', '', $default_font_size - 1);

		$diffsizetitle = (!getDolGlobalString('PDF_DIFFSIZE_TITLE') ? 3 : $conf->global->PDF_DIFFSIZE_TITLE);

		// If France, show VAT mention if not applicable
		if ($this->emetteur->country_code == 'FR' && empty($mysoc->tva_assuj)) {
			$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
			$pdf->SetXY($this->marge_gauche, $posy);
			$pdf->MultiCell(100, 3, $outputlangs->transnoentities("VATIsNotUsedForInvoice"), 0, 'L', 0);

			$posy = $pdf->GetY() + 4;
		}

		$posxval = 52;

		$diffsizetitle = (!getDolGlobalString('PDF_DIFFSIZE_TITLE') ? 3 : $conf->global->PDF_DIFFSIZE_TITLE);

		// Show total weight if available
		if (!empty($object->array_options['options_poids_total'])) {
			$poids_total = intval($object->array_options['options_poids_total']); // Nombre entier uniquement
			$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
			$pdf->SetXY($this->marge_gauche, $posy);
			$pdf->MultiCell(100, 4, 'POIDS TOTAL = ' . $poids_total . ' Kg', 0, 'L');
			$posy = $pdf->GetY() + 1;
		}


		// Check a payment mode is defined
		/* Not used with orders
		if (empty($object->mode_reglement_code)
			&& ! $conf->global->FACTURE_CHQ_NUMBER
			&& ! $conf->global->FACTURE_RIB_NUMBER)
		{
			$pdf->SetXY($this->marge_gauche, $posy);
			$pdf->SetTextColor(200,0,0);
			$pdf->SetFont('','B', $default_font_size - 2);
			$pdf->MultiCell(80, 3, $outputlangs->transnoentities("ErrorNoPaiementModeConfigured"),0,'L',0);
			$pdf->SetTextColor(0,0,0);

			$posy=$pdf->GetY()+1;
		}
		*/
		/* TODO
		else if (!empty($object->availability_code))
		{
			$pdf->SetXY($this->marge_gauche, $posy);
			$pdf->SetTextColor(200,0,0);
			$pdf->SetFont('','B', $default_font_size - 2);
			$pdf->MultiCell(80, 3, $outputlangs->transnoentities("AvailabilityPeriod").': '.,0,'L',0);
			$pdf->SetTextColor(0,0,0);

			$posy=$pdf->GetY()+1;
		}*/

		// Show planed date of delivery
		if (!empty($object->delivery_date)) {
			$outputlangs->load("sendings");
			$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
			$pdf->SetXY($this->marge_gauche, $posy);
			$titre = $outputlangs->transnoentities("DateDeliveryPlanned").':';
			$pdf->MultiCell(80, 4, $titre, 0, 'L');
			$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
			$pdf->SetXY($posxval, $posy);
			$dlp = dol_print_date($object->delivery_date, "daytext", false, $outputlangs, true);
			$pdf->MultiCell(80, 4, $dlp, 0, 'L');

			$posy = $pdf->GetY() + 1;
		} elseif ($object->availability_code || $object->availability) {    // Show availability conditions
			$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
			$pdf->SetXY($this->marge_gauche, $posy);
			$titre = $outputlangs->transnoentities("AvailabilityPeriod").':';
			$pdf->MultiCell(80, 4, $titre, 0, 'L');
			$pdf->SetTextColor(0, 0, 0);
			$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
			$pdf->SetXY($posxval, $posy);
			$lib_availability = $outputlangs->transnoentities("AvailabilityType".$object->availability_code) != 'AvailabilityType'.$object->availability_code ? $outputlangs->transnoentities("AvailabilityType".$object->availability_code) : $outputlangs->convToOutputCharset(isset($object->availability) ? $object->availability : '');
			$lib_availability = str_replace('\n', "\n", $lib_availability);
			$pdf->MultiCell(80, 4, $lib_availability, 0, 'L');

			$posy = $pdf->GetY() + 1;
		}

		// Payment mode section removed - only payment conditions are displayed

		return $posy;
	}


	/**
	 *	Show total to pay
	 *
	 *	@param	TCPDF		$pdf            Object PDF
	 *	@param  Commande	$object         Object to show
	 *	@param  int			$deja_regle     Montant deja regle
	 *	@param	int			$posy			Position depart
	 *	@param	Translate	$outputlangs	Objet langs
	 *  @param  Translate	$outputlangsbis	Object lang for output bis
	 *	@return int							Position pour suite
	 */
	protected function drawTotalTable(&$pdf, $object, $deja_regle, $posy, $outputlangs, $outputlangsbis = null)
	{
		global $conf, $mysoc, $hookmanager;

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		if (getDolGlobalString('PDF_USE_ALSO_LANGUAGE_CODE') && $outputlangs->defaultlang != $conf->global->PDF_USE_ALSO_LANGUAGE_CODE) {
			$outputlangsbis = new Translate('', $conf);
			$outputlangsbis->setDefaultLang($conf->global->PDF_USE_ALSO_LANGUAGE_CODE);
			$outputlangsbis->loadLangs(array("main", "dict", "companies", "bills", "products", "propal"));
			$default_font_size--;
		}

		$tab2_top = $posy;
		$tab2_hl = 4;
		$pdf->SetFont('', '', $default_font_size - 1);

		// Total table
		// col1x aligned with the CGV block start (right half of the page)
		$col1x = $this->marge_gauche + ($this->page_largeur - $this->marge_gauche - $this->marge_droite - 5) / 2 + 5;
		$col2x = $col1x + ($this->page_largeur - $this->marge_droite - $col1x) / 2; // 50/50 split
		$largcol2 = ($this->page_largeur - $this->marge_droite - $col2x);

		$useborder = 0;
		$index = 0;

		// Total HT
		$pdf->SetFillColor(224, 224, 224);
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size);
		$pdf->SetXY($col1x, $tab2_top);
		$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("TotalHT").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("TotalHT") : ''), $useborder, 'L', 1);
		$total_ht = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ht : $object->total_ht);
		$pdf->SetXY($col2x, $tab2_top);
		$pdf->MultiCell($largcol2, $tab2_hl, price($total_ht + (!empty($object->remise) ? $object->remise : 0), 0, $outputlangs), $useborder, 'R', 1);
		$pdf->SetFont('', '', $default_font_size);

		// Show VAT by rates and total
		$pdf->SetFillColor(248, 248, 248);

		$total_ttc = (isModEnabled("multicurrency") && $object->multicurrency_tx != 1) ? $object->multicurrency_total_ttc : $object->total_ttc;

		$this->atleastoneratenotnull = 0;
		if (!getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT')) {
			$tvaisnull = ((!empty($this->tva) && count($this->tva) == 1 && isset($this->tva['0.000']) && is_float($this->tva['0.000'])) ? true : false);
			if (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_WITHOUT_VAT_IFNULL') && $tvaisnull) {
				// Nothing to do
			} else {
				//Local tax 1 before VAT
				//if (!empty($conf->global->FACTURE_LOCAL_TAX1_OPTION) && $conf->global->FACTURE_LOCAL_TAX1_OPTION=='localtax1on')
				//{
				foreach ($this->localtax1 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('1', '3', '5'))) {
						continue;
					}
					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;
							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT1", $mysoc->country_code) : '');
							$totalvat .= ' ';
							$totalvat .= vatrate(abs($tvakey), 1).$tvacompl;
							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', 1);
						}
					}
				}
				//}
				//Local tax 2 before VAT
				//if (!empty($conf->global->FACTURE_LOCAL_TAX2_OPTION) && $conf->global->FACTURE_LOCAL_TAX2_OPTION=='localtax2on')
				//{
				foreach ($this->localtax2 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('1', '3', '5'))) {
						continue;
					}
					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;

							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT2", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT2", $mysoc->country_code) : '');
							$totalvat .= ' ';
							$totalvat .= vatrate(abs($tvakey), 1).$tvacompl;
							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', 1);
						}
					}
				}
				//}

				//Local tax 1 after VAT
				//if (!empty($conf->global->FACTURE_LOCAL_TAX1_OPTION) && $conf->global->FACTURE_LOCAL_TAX1_OPTION=='localtax1on')
				//{
				foreach ($this->localtax1 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('2', '4', '6'))) {
						continue;
					}

					foreach ($localtax_rate as $tvakey => $tvaval) {
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;

							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT1", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT1", $mysoc->country_code) : '');
							$totalvat .= ' ';
							$totalvat .= vatrate(abs($tvakey), 1).$tvacompl;

							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', 1);
						}
					}
				}
				//}
				//Local tax 2 after VAT
				//if (!empty($conf->global->FACTURE_LOCAL_TAX2_OPTION) && $conf->global->FACTURE_LOCAL_TAX2_OPTION=='localtax2on')
				//{
				foreach ($this->localtax2 as $localtax_type => $localtax_rate) {
					if (in_array((string) $localtax_type, array('2', '4', '6'))) {
						continue;
					}

					foreach ($localtax_rate as $tvakey => $tvaval) {
						// retrieve global local tax
						if ($tvakey != 0) {    // On affiche pas taux 0
							//$this->atleastoneratenotnull++;

							$index++;
							$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);

							$tvacompl = '';
							if (preg_match('/\*/', $tvakey)) {
								$tvakey = str_replace('*', '', $tvakey);
								$tvacompl = " (".$outputlangs->transnoentities("NonPercuRecuperable").")";
							}
							$totalvat = $outputlangs->transcountrynoentities("TotalLT2", $mysoc->country_code).(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transcountrynoentities("TotalLT2", $mysoc->country_code) : '');
							$totalvat .= ' ';

							$totalvat .= vatrate(abs($tvakey), 1).$tvacompl;
							$pdf->MultiCell($col2x - $col1x, $tab2_hl, $totalvat, 0, 'L', 1);

							$total_localtax = ((isModEnabled("multicurrency") && isset($object->multicurrency_tx) && $object->multicurrency_tx != 1) ? price2num($tvaval * $object->multicurrency_tx, 'MT') : $tvaval);

							$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
							$pdf->MultiCell($largcol2, $tab2_hl, price($total_localtax, 0, $outputlangs), 0, 'R', 1);
						}
					}
				}
				//}

			}
		}

		$pdf->SetTextColor(0, 0, 0);

		$creditnoteamount = 0;
		$depositsamount = 0;
		//$creditnoteamount=$object->getSumCreditNotesUsed();
		//$depositsamount=$object->getSumDepositsUsed();
		//print "x".$creditnoteamount."-".$depositsamount;exit;
		$resteapayer = price2num($total_ttc - $deja_regle - $creditnoteamount - $depositsamount, 'MT');
		if (!empty($object->paye)) {
			$resteapayer = 0;
		}

		if ($deja_regle > 0) {
			// Already paid + Deposits
			$index++;

			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("AlreadyPaid").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("AlreadyPaid") : ''), 0, 'L', 0);
			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($deja_regle, 0, $outputlangs), 0, 'R', 0);

			$index++;
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetFillColor(224, 224, 224);
			$pdf->SetXY($col1x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($col2x - $col1x, $tab2_hl, $outputlangs->transnoentities("RemainderToPay").(is_object($outputlangsbis) ? ' / '.$outputlangsbis->transnoentities("RemainderToPay") : ''), $useborder, 'L', 1);

			$pdf->SetXY($col2x, $tab2_top + $tab2_hl * $index);
			$pdf->MultiCell($largcol2, $tab2_hl, price($resteapayer, 0, $outputlangs), $useborder, 'R', 1);

			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetTextColor(0, 0, 0);
		}


		// Conditions de paiement et mode de règlement affichés dans le bloc signature (colonne droite)
		$diffsizetitle = (!getDolGlobalString('PDF_DIFFSIZE_TITLE') ? 3 : $conf->global->PDF_DIFFSIZE_TITLE);

		$pdf->SetTextColor(0, 0, 0);

		// Ligne fluo et signature : positionnées juste après le bloc des totaux (HT/TVA/TTC)
		$posy_after_totals = $tab2_top + $tab2_hl * ($index + 1) + 2; // +2mm d'espace au-dessus de la ligne fluo

		// Ligne fluo : demande de retour de l'AR signé (pleine largeur = même largeur que le bloc signature)
		$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle + 2);
		$pdf->SetFillColor(255, 255, 0); // Jaune fluo
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetXY($this->marge_gauche, $posy_after_totals);
		$largeur_ligne = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
		$pdf->MultiCell($largeur_ligne, 4, "NOUS RETOURNER VALIDATION DE CET AR (DATÉ ET SIGNÉ) SOUS 24h.", 0, 'C', 1);

		$posy_after_totals = $pdf->GetY() + 2;

		// Zone en deux colonnes : signature à gauche, conditions/mode + CGV à droite
		$pdf->SetFillColor(255, 255, 255); // Fond blanc
		$pdf->SetTextColor(0, 0, 0);

		// Calcul des largeurs des colonnes (égales)
		$espace_entre_colonnes = 5;
		$largeur_colonne = ($largeur_ligne - $espace_entre_colonnes) / 2;

		// Colonne gauche : BON POUR ACCORD dans un cadre (hauteur augmentée)
		$hauteur_cadre_signature = 23;
		$pdf->Rect($this->marge_gauche, $posy_after_totals, $largeur_colonne, $hauteur_cadre_signature);
		$pdf->SetXY($this->marge_gauche + 1, $posy_after_totals + 1);
		$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
		$pdf->MultiCell($largeur_colonne - 2, 4, "BON POUR ACCORD (TAMPON + SIGNATURE) :", 0, 'L', 0);

		// Colonne droite : conditions de paiement, mode de règlement (police identique à l'ancienne version), puis CGV
		$x_droite = $this->marge_gauche + $largeur_colonne + $espace_entre_colonnes;
		$posy_droite = $posy_after_totals;

		if ($object->cond_reglement_code || $object->cond_reglement) {
			$lib_condition_paiement = ($outputlangs->transnoentities("PaymentCondition".$object->cond_reglement_code) != 'PaymentCondition'.$object->cond_reglement_code) ? $outputlangs->transnoentities("PaymentCondition".$object->cond_reglement_code) : $outputlangs->convToOutputCharset($object->cond_reglement_doc ? $object->cond_reglement_doc : $object->cond_reglement_label);
			$lib_condition_paiement = str_replace('\n', "\n", $lib_condition_paiement);
			if ($object->deposit_percent > 0) {
				$lib_condition_paiement = str_replace('__DEPOSIT_PERCENT__', $object->deposit_percent, $lib_condition_paiement);
			}
			$label_cond = $outputlangs->transnoentities("PaymentConditions").': ';
			$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
			$w_label_cond = $pdf->GetStringWidth($label_cond);
			$pdf->SetXY($x_droite, $posy_droite);
			$pdf->Cell($w_label_cond, $tab2_hl, $label_cond, 0, 0, 'L', 0);
			$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
			$pdf->MultiCell($largeur_colonne - $w_label_cond, $tab2_hl, $lib_condition_paiement, 0, 'L', 0);
			$posy_droite = $pdf->GetY();
		}

		if ($object->mode_reglement_code || $object->mode_reglement) {
			$lib_mode_reglement = ($outputlangs->transnoentities("PaymentType".$object->mode_reglement_code) != 'PaymentType'.$object->mode_reglement_code) ? $outputlangs->transnoentities("PaymentType".$object->mode_reglement_code) : $outputlangs->convToOutputCharset($object->mode_reglement);
			$label_mode = $outputlangs->transnoentities("PaymentMode").': ';
			$pdf->SetFont('', 'B', $default_font_size - $diffsizetitle);
			$w_label_mode = $pdf->GetStringWidth($label_mode);
			$pdf->SetXY($x_droite, $posy_droite);
			$pdf->Cell($w_label_mode, $tab2_hl, $label_mode, 0, 0, 'L', 0);
			$pdf->SetFont('', '', $default_font_size - $diffsizetitle);
			$pdf->MultiCell($largeur_colonne - $w_label_mode, $tab2_hl, $lib_mode_reglement, 0, 'L', 0);
			$posy_droite = $pdf->GetY();
		}

		$pdf->SetFont('', '', $default_font_size - $diffsizetitle - 1);
		$pdf->SetXY($x_droite, $posy_droite);
		$texte_cgv = "Différence de bains possible pour suite de chantiers\n";
		$texte_cgv .= "Toute commande quelle qu'en soit la forme et le moyen de transmission, reçue par DIAMANT INDUSTRlE, implique leur acceptation sans réserve: voir Conditions Générales de Vente. Attribution de compétences : le règlement de tout litige entre les parties, quel qu'en soit la nature et la cause sera soumis aux tribunaux de Brest.";
		$pdf->MultiCell($largeur_colonne, 3, $texte_cgv, 0, 'L', 0);

		// Positionner Y après la zone la plus haute (signature ou conditions)
		$posy_apres_signature = $posy_after_totals + $hauteur_cadre_signature + 1;
		$posy_apres_conditions = $pdf->GetY();
		$pdf->SetY(max($posy_apres_signature, $posy_apres_conditions));

		$posy_after_totals = $pdf->GetY() + 3;

		$index++;
		if (!empty($posy_after_totals)) {
			return $posy_after_totals;
		}
		return ($tab2_top + ($tab2_hl * $index));
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *   Show table for lines
	 *
	 *   @param		TCPDF		$pdf     		Object PDF
	 *   @param		string		$tab_top		Top position of table
	 *   @param		string		$tab_height		Height of table (rectangle)
	 *   @param		int			$nexY			Y (not used)
	 *   @param		Translate	$outputlangs	Langs object
	 *   @param		int			$hidetop		1=Hide top bar of array and title, 0=Hide nothing, -1=Hide only title
	 *   @param		int			$hidebottom		Hide bottom bar of array
	 *   @param		string		$currency		Currency code
	 *   @param		Translate	$outputlangsbis	Langs object bis
	 *   @return	void
	 */
	protected function _tableau(&$pdf, $tab_top, $tab_height, $nexY, $outputlangs, $hidetop = 0, $hidebottom = 0, $currency = '', $outputlangsbis = null)
	{
		global $conf;

		// Force to disable hidetop and hidebottom
		$hidebottom = 0;
		if ($hidetop) {
			$hidetop = -1;
		}

		$currency = !empty($currency) ? $currency : $conf->currency;
		$default_font_size = pdf_getPDFFontSize($outputlangs);

		// Amount in (at tab_top - 1)
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', '', $default_font_size - 2);

		if (empty($hidetop)) {
			$titre = $outputlangs->transnoentities("AmountInCurrency", $outputlangs->transnoentitiesnoconv("Currency".$currency));
			if (getDolGlobalInt('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
				$titre .= ' - '.$outputlangsbis->transnoentities("AmountInCurrency", $outputlangsbis->transnoentitiesnoconv("Currency".$currency));
			}

			$pdf->SetXY($this->page_largeur - $this->marge_droite - ($pdf->GetStringWidth($titre) + 3), $tab_top - 4);
			$pdf->MultiCell(($pdf->GetStringWidth($titre) + 3), 2, $titre);

			//$conf->global->MAIN_PDF_TITLE_BACKGROUND_COLOR='230,230,230';
			if (getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')) {
				$pdf->Rect($this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_droite - $this->marge_gauche, $this->tabTitleHeight, 'F', null, explode(',', getDolGlobalString('MAIN_PDF_TITLE_BACKGROUND_COLOR')));
			}
		}

		$pdf->SetDrawColor(128, 128, 128);
		$pdf->SetFont('', '', $default_font_size - 1);

		// Output Rect
		$this->printRect($pdf, $this->marge_gauche, $tab_top, $this->page_largeur - $this->marge_gauche - $this->marge_droite, $tab_height, $hidetop, $hidebottom); // Rect takes a length in 3rd parameter and 4th parameter


		$this->pdfTabTitles($pdf, $tab_top, $tab_height, $outputlangs, $hidetop);

		if (empty($hidetop)) {
			$pdf->line($this->marge_gauche, $tab_top + $this->tabTitleHeight, $this->page_largeur - $this->marge_droite, $tab_top + $this->tabTitleHeight); // line takes a position y in 2nd parameter and 4th parameter
		}
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *  Show top header of page.
	 *
	 *  @param	TCPDF		$pdf     		Object PDF
	 *  @param  Commande	$object     	Object to show
	 *  @param  int	    	$showaddress    0=no, 1=yes
	 *  @param  Translate	$outputlangs	Object lang for output
	 *  @param  Translate	$outputlangsbis	Object lang for output bis
	 *  @param	string		$titlekey		Translation key to show as title of document
	 *  @return	float|int                   Return topshift value
	 */
	protected function _pagehead(&$pdf, $object, $showaddress, $outputlangs, $outputlangsbis = null, $titlekey = "PdfOrderTitle")
	{
		// phpcs:enable
		global $conf, $langs, $hookmanager, $mysoc;

		$ltrdirection = 'L';
		if ($outputlangs->trans("DIRECTION") == 'rtl') {
			$ltrdirection = 'R';
		}

		// Load traductions files required by page
		$outputlangs->loadLangs(array("main", "bills", "propal", "orders", "companies"));

		$default_font_size = pdf_getPDFFontSize($outputlangs);

		pdf_pagehead($pdf, $outputlangs, $this->page_hauteur);

		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetFont('', 'B', $default_font_size + 3);

		$w = 100;

		$posy = $this->marge_haute;
		$posx = $this->page_largeur - $this->marge_droite - $w;

		$pdf->SetXY($this->marge_gauche, $posy);

		// Logo
		if (!getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO')) {
			if ($this->emetteur->logo) {
				$logodir = $conf->mycompany->dir_output;
				if (!empty(getMultidirOutput($mysoc, 'mycompany'))) {
					$logodir = getMultidirOutput($mysoc, 'mycompany');
				}
				if (!getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO')) {
					$logo = $logodir.'/logos/thumbs/'.$this->emetteur->logo_small;
				} else {
					$logo = $logodir.'/logos/'.$this->emetteur->logo;
				}
				if (is_readable($logo)) {
					$height = pdf_getHeightForLogo($logo);
					$pdf->Image($logo, $this->marge_gauche, $posy, 0, $height); // width=0 (auto)
				} else {
					$pdf->SetTextColor(200, 0, 0);
					$pdf->SetFont('', 'B', $default_font_size - 2);
					$pdf->MultiCell($w, 3, $outputlangs->transnoentities("ErrorLogoFileNotFound", $logo), 0, 'L');
					$pdf->MultiCell($w, 3, $outputlangs->transnoentities("ErrorGoToGlobalSetup"), 0, 'L');
				}
			} else {
				$text = $this->emetteur->name;
				$pdf->MultiCell($w, 4, $outputlangs->convToOutputCharset($text), 0, 'L');
			}
		}

		$pdf->SetFont('', 'B', $default_font_size + 3);
		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);
		$title = "A.R. DE COMMANDE";
		if (getDolGlobalInt('PDF_USE_ALSO_LANGUAGE_CODE') && is_object($outputlangsbis)) {
			$title .= ' - ';
			$title .= $outputlangsbis->transnoentities($titlekey);
		}
		$title .= ' '.$outputlangs->convToOutputCharset($object->ref);
		// Ajouter la version si l'extrafield existe
		if (!empty($object->array_options['options_version'])) {
			$title .= ' V'.$object->array_options['options_version'];
		}
		if ($object->statut == $object::STATUS_DRAFT) {
			$pdf->SetTextColor(128, 0, 0);
			$title .= ' - '.$outputlangs->transnoentities("NotValidated");
		}

		$pdf->MultiCell($w, 3, $title, '', 'R');

		$pdf->SetFont('', 'B', $default_font_size);

		/*
		$posy += 5;
		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);
		$textref = $outputlangs->transnoentities("Ref")." : ".$outputlangs->convToOutputCharset($object->ref);
		if ($object->statut == $object::STATUS_DRAFT) {
			$pdf->SetTextColor(128, 0, 0);
			$textref .= ' - '.$outputlangs->transnoentities("NotValidated");
		}
		$pdf->MultiCell($w, 4, $textref, '', 'R');
		*/

		$posy += 3;
		$pdf->SetFont('', '', $default_font_size - 2);

		if ($object->ref_client) {
			$posy += 4;
			$pdf->SetXY($posx, $posy);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->MultiCell($w, 3, $outputlangs->transnoentities("RefCustomer")." : ".dol_trunc($outputlangs->convToOutputCharset($object->ref_client), 65), '', 'R');
		}

		if (getDolGlobalInt('PDF_SHOW_PROJECT_TITLE')) {
			$object->fetch_projet();
			if (!empty($object->project->ref)) {
				$posy += 3;
				$pdf->SetXY($posx, $posy);
				$pdf->SetTextColor(0, 0, 60);
				$pdf->MultiCell($w, 3, $outputlangs->transnoentities("Project")." : ".(empty($object->project->title) ? '' : $object->project->title), '', 'R');
			}
		}

		if (getDolGlobalInt('PDF_SHOW_PROJECT')) {
			$object->fetch_projet();
			if (!empty($object->project->ref)) {
				$outputlangs->load("projects");
				$posy += 3;
				$pdf->SetXY($posx, $posy);
				$pdf->SetTextColor(0, 0, 60);
				$pdf->MultiCell($w, 3, $outputlangs->transnoentities("RefProject")." : ".(empty($object->project->ref) ? '' : $object->project->ref), '', 'R');
			}
		}

		$posy += 4;

		$pdf->SetXY($posx, $posy);
		$pdf->SetTextColor(0, 0, 60);
		$pdf->MultiCell($w, 3, "du ".dol_print_date(dol_now(), "day", false, $outputlangs, true), '', 'R');

		if (!getDolGlobalString('MAIN_PDF_HIDE_CUSTOMER_CODE') && !empty($object->thirdparty->code_client)) {
			$posy += 4;
			$pdf->SetXY($posx, $posy);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->MultiCell($w, 3, $outputlangs->transnoentities("CustomerCode")." : ".$outputlangs->transnoentities($object->thirdparty->code_client), '', 'R');
		}

		// Get contact
		if (getDolGlobalInt('DOC_SHOW_FIRST_SALES_REP')) {
			$arrayidcontact = $object->getIdContact('internal', 'SALESREPFOLL');
			if (count($arrayidcontact) > 0) {
				$usertmp = new User($this->db);
				$usertmp->fetch($arrayidcontact[0]);
				$posy += 4;
				$pdf->SetXY($posx, $posy);
				$pdf->SetTextColor(0, 0, 60);
				$pdf->MultiCell($w, 3, $langs->transnoentities("SalesRepresentative")." : ".$usertmp->getFullName($langs), '', 'R');
			}
		}

		$posy += 2;

		$top_shift = 0;
		// Show list of linked objects
		$current_y = $pdf->getY();
		$posy = pdf_writeLinkedObjects($pdf, $object, $outputlangs, $posx, $posy, $w, 3, 'R', $default_font_size);
		if ($current_y < $pdf->getY()) {
			$top_shift = $pdf->getY() - $current_y;
		}

		if ($showaddress) {
			// BLOC "Adresse de livraison" (anciennement "Emetteur")
			// Get shipping contact
			$carac_emetteur = '';
			$arrayidcontact = $object->getIdContact('external', 'SHIPPING');
			if (count($arrayidcontact) > 0) {
				$object->fetch_contact($arrayidcontact[0]);
				if ($object->contact) {
					// Nom du contact
					$carac_emetteur .= $outputlangs->convToOutputCharset($object->contact->getFullName($outputlangs)) . "\n";

					// Adresse du contact - construction manuelle
					if (!empty($object->contact->address)) {
						$carac_emetteur .= $object->contact->address . "\n";
					}

					// Code postal et ville
					$carac_emetteur .= $outputlangs->convToOutputCharset($object->contact->zip);
					if (!empty($object->contact->town)) {
						$carac_emetteur .= ' ' . $outputlangs->convToOutputCharset($object->contact->town);
					}
					$carac_emetteur .= "\n";

					// Pays si différent
					if (!empty($object->contact->country_code) && $object->contact->country_code != $this->emetteur->country_code) {
						$carac_emetteur .= $outputlangs->convToOutputCharset($outputlangs->transnoentitiesnoconv("Country".$object->contact->country_code)) . "\n";
					}

					// Téléphone
					if (!empty($object->contact->phone_pro)) {
						$carac_emetteur .= "\n" . $outputlangs->transnoentities("Phone") . ": " . $object->contact->phone_pro . "\n";
					} elseif (!empty($object->contact->phone_mobile)) {
						$carac_emetteur .= "\n" . $outputlangs->transnoentities("Phone") . ": " . $object->contact->phone_mobile . "\n";
					}

					// Email
					if (!empty($object->contact->email)) {
						$carac_emetteur .= $outputlangs->transnoentities("Email") . ": " . $object->contact->email;
					}

					// Autre contact (extrafield)
					if (!empty($object->contact->array_options['options_autre_contact'])) {
						$carac_emetteur .= "\n" . $outputlangs->convToOutputCharset($object->contact->array_options['options_autre_contact']);
					}
				}
			}

			// Show shipping address block
			$posy = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 30 : 32;
			$posy += $top_shift;
			$posx = $this->marge_gauche;
			if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->page_largeur - $this->marge_droite - 80;
			}

			$hautcadre = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 38 : 40;
			$widthrecbox = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 82;


			// Show shipping address frame
			if (!getDolGlobalString('MAIN_PDF_NO_SENDER_FRAME')) {
				$pdf->SetTextColor(0, 0, 0);
				$pdf->SetFont('', '', $default_font_size - 2);
				$pdf->SetXY($posx, $posy - 5);
				$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("Adresse de livraison"), 0, $ltrdirection);
				$pdf->SetXY($posx, $posy);
				$pdf->SetFillColor(230, 230, 230);
				$pdf->MultiCell($widthrecbox, $hautcadre, "", 0, 'R', 1);
				$pdf->SetTextColor(0, 0, 60);
			}

			// Show shipping address content
			$pdf->SetXY($posx + 2, $posy + 3);
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->MultiCell($widthrecbox - 2, 4, $carac_emetteur, 0, $ltrdirection);

			// BLOC "Adressé à" - Informations du tiers
			$thirdparty = $object->thirdparty;

			// Nom du tiers
			$carac_client = '';
			if (is_object($thirdparty)) {
				$carac_client = pdfBuildThirdpartyName($thirdparty, $outputlangs) . "\n";

				// Adresse du tiers
				$carac_client .= pdf_build_address($outputlangs, $this->emetteur, $thirdparty, '', 0, 'target', $object);

				// Téléphone et Fax
				if (!empty($thirdparty->phone)) {
					$line = "\n" . $outputlangs->transnoentities("Phone") . ": " . $thirdparty->phone;
					if (!empty($thirdparty->fax)) {
						$line .= " - " . $outputlangs->transnoentities("Fax") . ": " . $thirdparty->fax;
					}
					$carac_client .= $line;
				} elseif (!empty($thirdparty->fax)) {
					$carac_client .= "\n" . $outputlangs->transnoentities("Fax") . ": " . $thirdparty->fax;
				}

				// Email
				if (!empty($thirdparty->email)) {
					$carac_client .= "\n" . $outputlangs->transnoentities("Email") . ": " . $thirdparty->email;
				}

				// Extrafield contacts de la commande - liste d'adresses mail séparées par point-virgule
				if (!empty($object->array_options['options_contacts'])) {
					$contacts = $object->array_options['options_contacts'];

					// Si c'est un tableau, le convertir en chaîne avec point-virgule
					if (is_array($contacts)) {
						$contacts = implode('; ', $contacts);
					}
					// Si c'est une chaîne avec des virgules, remplacer par des points-virgules
					elseif (is_string($contacts)) {
						// Remplacer les virgules par des points-virgules si nécessaire
						$contacts = str_replace(',', '; ', $contacts);
						// Nettoyer les espaces multiples
						$contacts = preg_replace('/\s*;\s*/', '; ', $contacts);
					}

					if (!empty($contacts)) {
						$carac_client .= "\n" . "Contact : " . $contacts;
					}
				}
			}

			// Show recipient
			$widthrecbox = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 92 : 100;
			if ($this->page_largeur < 210) {
				$widthrecbox = 84; // To work with US executive format
			}
			$posy = getDolGlobalInt('MAIN_PDF_USE_ISO_LOCATION') ? 30 : 32;
			$posy += $top_shift;
			$posx = $this->page_largeur - $this->marge_droite - $widthrecbox;
			if (getDolGlobalInt('MAIN_INVERT_SENDER_RECIPIENT')) {
				$posx = $this->marge_gauche;
			}

			// Calcul de la hauteur dynamique du bloc "Adressé à" selon le contenu réel
			$pdf->SetFont('', '', $default_font_size - 1);
			$nbLignesClient = $pdf->getNumLines($carac_client, $widthrecbox - 4);
			// 4mm par ligne + 3mm padding haut (SetXY posy+3) + 3mm marge basse
			$hautcadre_client = max($hautcadre, $nbLignesClient * 4 + 6);
			$extra_shift = max(0, $hautcadre_client - $hautcadre);

			// Show recipient frame
			if (!getDolGlobalString('MAIN_PDF_NO_RECIPENT_FRAME')) {
				$pdf->SetTextColor(0, 0, 0);
				$pdf->SetFont('', '', $default_font_size - 2);
				$pdf->SetXY($posx + 2, $posy - 5);
				$pdf->MultiCell($widthrecbox, 5, $outputlangs->transnoentities("Adressé à"), 0, $ltrdirection);
				$pdf->Rect($posx, $posy, $widthrecbox, $hautcadre_client);
			}

			// Show recipient information
			$pdf->SetXY($posx + 2, $posy + 3);
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->MultiCell($widthrecbox, 4, $carac_client, 0, $ltrdirection);

			// Position le curseur après le bloc destinataire (hauteur dynamique)
			$pdf->SetY($posy + $hautcadre_client + 2);

			// Répercuter le surplus de hauteur sur top_shift pour décaler le reste du document
			$top_shift += $extra_shift;
		}

		$pdf->SetTextColor(0, 0, 0);
		return $top_shift;
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.PublicUnderscore
	/**
	 *   	Show footer of page. Need this->emetteur object
	 *
	 *   	@param	TCPDF		$pdf     			PDF
	 * 		@param	Commande	$object				Object to show
	 *      @param	Translate	$outputlangs		Object lang for output
	 *      @param	int			$hidefreetext		1=Hide free text
	 *      @return	int								Return height of bottom margin including footer text
	 */
	protected function _pagefoot(&$pdf, $object, $outputlangs, $hidefreetext = 0)
	{
		// phpcs:enable
		$showdetails = getDolGlobalInt('MAIN_GENERATE_DOCUMENTS_SHOW_FOOT_DETAILS', 0);
		return pdf_pagefoot($pdf, $outputlangs, 'ORDER_FREE_TEXT', $this->emetteur, $this->marge_basse, $this->marge_gauche, $this->page_hauteur, $object, $showdetails, $hidefreetext, $this->page_largeur, $this->watermark);
	}



	/**
	 *  Draw the "Fiche de renseignement client" page (appended when extrafield info_client is checked).
	 *
	 *  @param  TCPDF       $pdf            Object PDF
	 *  @param  Commande    $object         Object order
	 *  @param  Translate   $outputlangs    Langs object
	 *  @return void
	 */
	protected function _drawClientInfoPage(&$pdf, $object, $outputlangs)
	{
		global $conf, $mysoc;

		$default_font_size = pdf_getPDFFontSize($outputlangs);
		$thirdparty = $object->thirdparty;

		// Load thirdparty extrafields if not already loaded
		$thirdparty->fetch_optionals();

		// Add new page
		$pdf->AddPage();
		$pdf->SetMargins($this->marge_gauche, $this->marge_haute, $this->marge_droite);
		$pdf->SetAutoPageBreak(true, $this->marge_basse + 10);
		$pdf->SetTextColor(0, 0, 0);

		$pageWidth = $this->page_largeur - $this->marge_gauche - $this->marge_droite;
		$labelW = 58;
		$valueW = $pageWidth - $labelW;

		$posy = $this->marge_haute;

		// === LOGO (haut gauche) ===
		$logo_height = 12;
		if (!getDolGlobalInt('PDF_DISABLE_MYCOMPANY_LOGO') && $this->emetteur->logo) {
			$logodir = $conf->mycompany->dir_output;
			if (!empty(getMultidirOutput($mysoc, 'mycompany'))) {
				$logodir = getMultidirOutput($mysoc, 'mycompany');
			}
			$logo = getDolGlobalInt('MAIN_PDF_USE_LARGE_LOGO')
				? $logodir.'/logos/'.$this->emetteur->logo
				: $logodir.'/logos/thumbs/'.$this->emetteur->logo_small;
			if (is_readable($logo)) {
				$logo_height = pdf_getHeightForLogo($logo);
				$pdf->Image($logo, $this->marge_gauche, $posy, 0, $logo_height);
			}
		}

		// === TITRE : même police, taille et couleur que le document principal ===
		$pdf->SetFont('', 'B', $default_font_size + 3);
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetXY($this->marge_gauche, $posy);
		$pdf->MultiCell($pageWidth, max($logo_height, 8), 'FICHE DE RENSEIGNEMENT CLIENT', 0, 'C', false, 1);
		$posy = $pdf->GetY() + 6;

		$pdf->SetTextColor(0, 0, 0);

		// -------------------------------------------------------
		// Helpers internes
		// -------------------------------------------------------

		// Dessine une ligne label (gras) + valeur sur une seule ligne
		$drawRow = function ($label, $value) use (&$pdf, &$posy, $default_font_size, $labelW, $valueW) {
			$pdf->SetXY($this->marge_gauche, $posy);
			$pdf->SetFont('', 'B', $default_font_size - 1);
			$pdf->Cell($labelW, 5, $label, 0, 0, 'L');
			$pdf->SetFont('', '', $default_font_size - 1);
			$pdf->SetXY($this->marge_gauche + $labelW, $posy);
			$pdf->MultiCell($valueW, 5, (string) $value, 0, 'L', false, 1);
			$posy = $pdf->GetY();
		};

		// Dessine un en-tête de section avec fond gris
		$drawSection = function ($title) use (&$pdf, &$posy, $default_font_size, $pageWidth) {
			$posy += 4;
			$pdf->SetFont('', 'B', $default_font_size);
			$pdf->SetFillColor(210, 210, 210);
			$pdf->SetTextColor(0, 0, 60);
			$pdf->SetXY($this->marge_gauche, $posy);
			$pdf->Cell($pageWidth, 6, $title, 0, 1, 'L', true);
			$pdf->SetFillColor(255, 255, 255);
			$pdf->SetTextColor(0, 0, 0);
			$posy = $pdf->GetY() + 2;
		};

		// -------------------------------------------------------
		// SECTION 1 : INFORMATIONS GÉNÉRALES DE LA SOCIÉTÉ
		// -------------------------------------------------------
		$drawSection('1. INFORMATIONS GÉNÉRALES DE LA SOCIÉTÉ');

		$drawRow('Raison sociale :', $outputlangs->convToOutputCharset($thirdparty->name));
		$drawRow('Adresse :', $outputlangs->convToOutputCharset($thirdparty->address));
		$drawRow('CP / Ville :', trim($thirdparty->zip.' '.$thirdparty->town));

		$country_label = '';
		if (!empty($thirdparty->country)) {
			$country_label = $outputlangs->convToOutputCharset($thirdparty->country);
		} elseif (!empty($thirdparty->country_code)) {
			$country_label = $outputlangs->transnoentitiesnoconv('Country'.$thirdparty->country_code);
		}
		$drawRow('Pays :', $country_label);
		$drawRow('Téléphone :', $thirdparty->phone);
		$drawRow('Fax :', $thirdparty->fax);
		$drawRow('E-mail :', $thirdparty->email);

		$posy += 2;
		$drawRow('SIREN :', $thirdparty->idprof1);
		$drawRow('SIRET :', $thirdparty->idprof2);
		$drawRow('NAF-APE :', $thirdparty->idprof3);
		$drawRow('N° DE TVA :', $thirdparty->tva_intra);

		// -------------------------------------------------------
		// SECTION 2 : INFORMATIONS DE FACTURATION
		// -------------------------------------------------------
		$drawSection('2. INFORMATIONS DE FACTURATION');

		// Contact de facturation : contact de ce tiers ayant le rôle BILLING (tous éléments confondus)
		// Correspond au statut "Facture - Contact client Facturation" visible sur la fiche tiers
		$billing_contact = null;

		$sql_billing = "SELECT DISTINCT ec.fk_socpeople"
			." FROM ".MAIN_DB_PREFIX."element_contact ec"
			." JOIN ".MAIN_DB_PREFIX."c_type_contact ctc ON ctc.rowid = ec.fk_c_type_contact"
			." JOIN ".MAIN_DB_PREFIX."socpeople sp ON sp.rowid = ec.fk_socpeople"
			." WHERE sp.fk_soc = ".((int) $thirdparty->id)
			." AND sp.entity IN (".getEntity('contact').")"
			." AND ctc.code = 'BILLING'"
			." AND ctc.source = 'external'"
			." ORDER BY ec.rowid DESC";
		$resql_billing = $this->db->query($sql_billing);
		if ($resql_billing) {
			$obj_billing = $this->db->fetch_object($resql_billing);
			if ($obj_billing) {
				$billing_contact = new Contact($this->db);
				if ($billing_contact->fetch($obj_billing->fk_socpeople) <= 0) {
					$billing_contact = null;
				}
			}
			$this->db->free($resql_billing);
		}

		if ($billing_contact) {
			$drawRow('Nom :', $outputlangs->convToOutputCharset($billing_contact->getFullName($outputlangs)));
			$drawRow('Adresse :', $outputlangs->convToOutputCharset($billing_contact->address));
			$drawRow('CP / Ville :', trim($billing_contact->zip.' '.$billing_contact->town));
			$bc_country = '';
			if (!empty($billing_contact->country)) {
				$bc_country = $outputlangs->convToOutputCharset($billing_contact->country);
			} elseif (!empty($billing_contact->country_code)) {
				$bc_country = $outputlangs->transnoentitiesnoconv('Country'.$billing_contact->country_code);
			}
			$drawRow('Pays :', $bc_country);
			$drawRow('Téléphone :', $billing_contact->phone_pro ?: $billing_contact->phone_mobile);
			$drawRow('Fax :', $billing_contact->fax);
			$drawRow('E-mail :', $billing_contact->email);
			$posy += 2;
		}

		// Conditions de règlement : depuis le tiers, avec fallback DB puis commande
		$cond_label = '';
		if (!empty($thirdparty->cond_reglement_code)) {
			$trans = $outputlangs->transnoentities('PaymentCondition'.$thirdparty->cond_reglement_code);
			$cond_label = ($trans !== 'PaymentCondition'.$thirdparty->cond_reglement_code)
				? $trans
				: $outputlangs->convToOutputCharset(!empty($thirdparty->cond_reglement_doc) ? $thirdparty->cond_reglement_doc : (isset($thirdparty->cond_reglement) ? $thirdparty->cond_reglement : ''));
		} elseif (!empty($thirdparty->cond_reglement_id)) {
			// Fallback : lecture directe en DB
			$sql_cond = "SELECT code, libelle FROM ".MAIN_DB_PREFIX."c_payment_term WHERE rowid = ".((int) $thirdparty->cond_reglement_id);
			$res_cond = $this->db->query($sql_cond);
			if ($res_cond && ($obj_cond = $this->db->fetch_object($res_cond))) {
				$trans = $outputlangs->transnoentities('PaymentCondition'.$obj_cond->code);
				$cond_label = ($trans !== 'PaymentCondition'.$obj_cond->code) ? $trans : $outputlangs->convToOutputCharset($obj_cond->libelle);
			}
		}
		// Fallback final : conditions de la commande
		if (empty($cond_label) && !empty($object->cond_reglement_code)) {
			$trans = $outputlangs->transnoentities('PaymentCondition'.$object->cond_reglement_code);
			$cond_label = ($trans !== 'PaymentCondition'.$object->cond_reglement_code)
				? $trans
				: $outputlangs->convToOutputCharset(!empty($object->cond_reglement_doc) ? $object->cond_reglement_doc : $object->cond_reglement_label);
		}
		$drawRow('Conditions de règlement :', $cond_label);

		// Mode de règlement : depuis le tiers, avec fallback DB puis commande
		$mode_label = '';
		if (!empty($thirdparty->mode_reglement_code)) {
			$trans = $outputlangs->transnoentities('PaymentType'.$thirdparty->mode_reglement_code);
			$mode_label = ($trans !== 'PaymentType'.$thirdparty->mode_reglement_code)
				? $trans
				: $outputlangs->convToOutputCharset(isset($thirdparty->mode_reglement) ? $thirdparty->mode_reglement : '');
		} elseif (!empty($thirdparty->mode_reglement_id)) {
			// Fallback : lecture directe en DB
			$sql_mode = "SELECT code, libelle FROM ".MAIN_DB_PREFIX."c_paiement WHERE id = ".((int) $thirdparty->mode_reglement_id);
			$res_mode = $this->db->query($sql_mode);
			if ($res_mode && ($obj_mode = $this->db->fetch_object($res_mode))) {
				$trans = $outputlangs->transnoentities('PaymentType'.$obj_mode->code);
				$mode_label = ($trans !== 'PaymentType'.$obj_mode->code) ? $trans : $outputlangs->convToOutputCharset($obj_mode->libelle);
				$thirdparty->mode_reglement_code = $obj_mode->code; // mémoriser pour le test RIB
			}
		}
		// Fallback final : mode de la commande
		if (empty($mode_label) && !empty($object->mode_reglement_code)) {
			$trans = $outputlangs->transnoentities('PaymentType'.$object->mode_reglement_code);
			$mode_label = ($trans !== 'PaymentType'.$object->mode_reglement_code)
				? $trans
				: $outputlangs->convToOutputCharset($object->mode_reglement);
			if (empty($thirdparty->mode_reglement_code)) {
				$thirdparty->mode_reglement_code = $object->mode_reglement_code;
			}
		}
		$drawRow('Mode de règlement :', $mode_label);

		// RIB si mode prélèvement (code PRE)
		if (!empty($thirdparty->mode_reglement_code) && $thirdparty->mode_reglement_code === 'PRE') {
			$bac = new CompanyBankAccount($this->db);
			if ($bac->fetch(0, $thirdparty->id) > 0) {
				$rib_lines = array();
				if (!empty($bac->iban)) {
					$rib_lines[] = 'IBAN : '.$bac->iban;
				}
				if (!empty($bac->bic)) {
					$rib_lines[] = 'BIC : '.$bac->bic;
				}
				if (!empty($bac->number)) {
					$rib_lines[] = 'N° compte : '.$bac->number;
				}
				if ($rib_lines) {
					$pdf->SetXY($this->marge_gauche, $posy);
					$pdf->SetFont('', '', $default_font_size - 1);
					$pdf->MultiCell($pageWidth, 5, implode("\n", $rib_lines), 0, 'L', false, 1);
					$posy = $pdf->GetY();
				}
			}
		}

		// Extrafield information_facturation du tiers
		if (!empty($thirdparty->array_options['options_information_facturation'])) {
			$drawRow('Informations de facturation :', $outputlangs->convToOutputCharset($thirdparty->array_options['options_information_facturation']));
		}

		// -------------------------------------------------------
		// SECTION 3 : CONTACTS DE L'ENTREPRISE
		// -------------------------------------------------------
		$drawSection('3. CONTACTS DE L\'ENTREPRISE');

		// IDs des contacts livraison à exclure
		$shipping_ids = $object->getIdContact('external', 'SHIPPING');

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."socpeople"
			." WHERE fk_soc = ".((int) $thirdparty->id)
			." AND entity IN (".getEntity('contact').")"
			." ORDER BY lastname, firstname";
		$resql = $this->db->query($sql);
		$has_contacts = false;
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if (in_array($obj->rowid, $shipping_ids)) {
					continue;
				}
				$c = new Contact($this->db);
				$c->fetch($obj->rowid);

				$contact_line = trim($c->firstname.' '.$c->lastname);
				if (!empty($c->email)) {
					$contact_line .= ' — '.$c->email;
				}

				$pdf->SetXY($this->marge_gauche + 2, $posy);
				$pdf->SetFont('', '', $default_font_size - 1);
				$pdf->MultiCell($pageWidth - 2, 5, $outputlangs->convToOutputCharset($contact_line), 0, 'L', false, 1);
				$posy = $pdf->GetY();
				$has_contacts = true;
			}
			$this->db->free($resql);
		}
		if (!$has_contacts) {
			$pdf->SetXY($this->marge_gauche + 2, $posy);
			$pdf->SetFont('', 'I', $default_font_size - 1);
			$pdf->MultiCell($pageWidth - 2, 5, '—', 0, 'L', false, 1);
			$posy = $pdf->GetY();
		}

		// -------------------------------------------------------
		// ENCART DE VALIDATION
		// -------------------------------------------------------
		$posy += 8;

		// Titre de l'encart
		$pdf->SetFont('', 'B', $default_font_size);
		$pdf->SetTextColor(0, 0, 60);
		$pdf->SetXY($this->marge_gauche, $posy);
		$pdf->MultiCell($pageWidth, 6, 'VALIDATION DES INFORMATIONS', 0, 'C', false, 1);
		$posy = $pdf->GetY() + 2;

		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFont('', '', $default_font_size - 1);
		$pdf->SetXY($this->marge_gauche, $posy);
		$pdf->MultiCell($pageWidth, 5, "Je soussigné(e), certifie l'exactitude des informations ci-dessus et m'engage à signaler toute modification.", 0, 'L', false, 1);
		$posy = $pdf->GetY() + 3;

		// Cadre de signature
		$sig_height = 32;
		$pdf->SetDrawColor(0, 0, 0);
		$pdf->Rect($this->marge_gauche, $posy, $pageWidth, $sig_height);

		$col_w = $pageWidth / 2;
		$pdf->SetFont('', '', $default_font_size - 1);

		$pdf->SetXY($this->marge_gauche + 2, $posy + 3);
		$pdf->MultiCell($pageWidth - 4, 5, 'Nom et prénom : .........................................................', 0, 'L', false, 1);

		$pdf->SetXY($this->marge_gauche + 2, $posy + 14);
		$pdf->Cell($col_w - 4, 5, 'Date : ..........................................', 0, 0, 'L');

		$pdf->SetXY($this->marge_gauche + $col_w + 2, $posy + 14);
		$pdf->Cell($col_w - 4, 5, 'Signature et cachet :', 0, 1, 'L');

		// Restaurer les paramètres PDF originaux et ajouter le pied de page avec numérotation
		$pdf->SetAutoPageBreak(1, 0);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetDrawColor(128, 128, 128);
		$this->_pagefoot($pdf, $object, $outputlangs);
	}


	/**
	 *   	Define Array Column Field
	 *
	 *   	@param	Commande		$object    		common object
	 *   	@param	Translate		$outputlangs    langs
	 *      @param	int				$hidedetails	Do not show line details
	 *      @param	int				$hidedesc		Do not show desc
	 *      @param	int				$hideref		Do not show ref
	 *      @return	void
	 */
	public function defineColumnField($object, $outputlangs, $hidedetails = 0, $hidedesc = 0, $hideref = 0)
	{
		global $conf, $hookmanager;

		// Default field style for content
		$this->defaultContentsFieldsStyle = array(
			'align' => 'R', // R,C,L
			'padding' => array(1, 0.5, 1, 0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
		);

		// Default field style for content
		$this->defaultTitlesFieldsStyle = array(
			'align' => 'C', // R,C,L
			'padding' => array(0.5, 0, 0.5, 0), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
		);

		/*
		 * For exemple
		 $this->cols['theColKey'] = array(
		 'rank' => $rank, // int : use for ordering columns
		 'width' => 20, // the column width in mm
		 'title' => array(
		 'textkey' => 'yourLangKey', // if there is no label, yourLangKey will be translated to replace label
		 'label' => ' ', // the final label : used fore final generated text
		 'align' => 'L', // text alignement :  R,C,L
		 'padding' => array(0.5,0.5,0.5,0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
		 ),
		 'content' => array(
		 'align' => 'L', // text alignement :  R,C,L
		 'padding' => array(0.5,0.5,0.5,0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
		 ),
		 );
		 */

		// Désignation - rank 0
		$rank = 0;
		$this->cols['desc'] = array(
			'rank' => $rank,
			'width' => false, // only for desc
			'status' => true,
			'title' => array(
				'textkey' => 'Designation', // use lang key is usefull in somme case with module
				'align' => 'L',
				// 'textkey' => 'yourLangKey', // if there is no label, yourLangKey will be translated to replace label
				// 'label' => ' ', // the final label
				'padding' => array(0.5, 0.5, 0.5, 0.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
			),
			'content' => array(
				'align' => 'L',
				'padding' => array(1, 0.5, 1, 1.5), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
			),
		);

		// Qté - rank 10
		$rank = 10;
		$this->cols['qty'] = array(
			'rank' => $rank,
			'width' => 20, // in mm (increased to fit "9999.999 m²")
			'status' => true,
			'title' => array(
				'textkey' => 'Qty'
			),
			'border-left' => false, // remove left line separator
		);

		// PU (Prix Unitaire) - rank 20
		$rank = 20;
		$this->cols['subprice'] = array(
			'rank' => $rank,
			'width' => 15, // in mm (reduced to compensate qty increase)
			'status' => true,
			'title' => array(
				'textkey' => 'PriceUHT'
			),
			'border-left' => false, // remove left line separator
		);

		// Adapt dynamically the width of subprice, if text is too long.
		$tmpwidth = 0;
		$nblines = count($object->lines);
		for ($i = 0; $i < $nblines; $i++) {
			$tmpwidth2 = dol_strlen(dol_string_nohtmltag(pdf_getlineupexcltax($object, $i, $outputlangs, $hidedetails)));
			$tmpwidth = max($tmpwidth, $tmpwidth2);
		}
		if ($tmpwidth > 10) {
			$this->cols['subprice']['width'] += (2 * ($tmpwidth - 10));
		}

		// Total HT - rank 30
		$rank = 30;
		$this->cols['totalexcltax'] = array(
			'rank' => $rank,
			'width' => 26, // in mm
			'status' => !getDolGlobalString('PDF_ORDER_HIDE_PRICE_EXCL_TAX') ? true : false,
			'title' => array(
				'textkey' => 'TotalHTShort'
			),
			'border-left' => false, // remove left line separator
		);

		// Image of product
		$rank = $rank + 10;
		$this->cols['photo'] = array(
			'rank' => $rank,
			'width' => (!getDolGlobalInt('MAIN_DOCUMENTS_WITH_PICTURE_WIDTH') ? 20 : getDolGlobalInt('MAIN_DOCUMENTS_WITH_PICTURE_WIDTH')), // in mm
			'status' => false,
			'title' => array(
				'textkey' => 'Photo',
				'label' => ' '
			),
			'content' => array(
				'padding' => array(0, 0, 0, 0), // Like css 0 => top , 1 => right, 2 => bottom, 3 => left
			),
			'border-left' => false, // remove left line separator
		);

		if (getDolGlobalInt('MAIN_GENERATE_ORDERS_WITH_PICTURE') && !empty($this->atleastonephoto)) {
			$this->cols['photo']['status'] = true;
		}

		$rank = $rank + 10;
		$this->cols['vat'] = array(
			'rank' => $rank,
			'status' => false,
			'width' => 16, // in mm
			'title' => array(
				'textkey' => 'VAT'
			),
			'border-left' => false, // remove left line separator
		);

		$rank = $rank + 10;
		$this->cols['unit'] = array(
			'rank' => $rank,
			'width' => 11, // in mm
			'status' => false,
			'title' => array(
				'textkey' => 'Unit'
			),
			'border-left' => false, // remove left line separator
		);

		$rank = $rank + 10;
		$this->cols['discount'] = array(
			'rank' => $rank,
			'width' => 13, // in mm
			'status' => false,
			'title' => array(
				'textkey' => 'ReductionShort'
			),
			'border-left' => false, // remove left line separator
		);
		if ($this->atleastonediscount) {
			$this->cols['discount']['status'] = true;
		}

		$rank = $rank + 1010; // add a big offset to be sure is the last col because default extrafield rank is 100
		$this->cols['totalincltax'] = array(
			'rank' => $rank,
			'width' => 26, // in mm
			'status' => !getDolGlobalString('PDF_ORDER_SHOW_PRICE_INCL_TAX') ? false : true,
			'title' => array(
				'textkey' => 'TotalTTCShort'
			),
			'border-left' => false, // remove left line separator
		);

		// Add extrafields cols
		if (!empty($object->lines)) {
			$line = reset($object->lines);
			$this->defineColumnExtrafield($line, $outputlangs, $hidedetails);
		}

		$parameters = array(
			'object' => $object,
			'outputlangs' => $outputlangs,
			'hidedetails' => $hidedetails,
			'hidedesc' => $hidedesc,
			'hideref' => $hideref
		);

		$reshook = $hookmanager->executeHooks('defineColumnField', $parameters, $this); // Note that $object may have been modified by hook
		if ($reshook < 0) {
			setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
		} elseif (empty($reshook)) {
			$this->cols = array_replace($this->cols, $hookmanager->resArray); // array_replace is used to preserve keys
		} else {
			$this->cols = $hookmanager->resArray;
		}
	}
}
