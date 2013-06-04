<? require_once("lib/tcpdf/tcpdf.php");

class ParametrizedPDF extends TCPDF {

	protected $footerItems = array();
	protected $headerItems = array();
	private $_theaderLeft;

	public function __construct($pageOrientation, $unit,$paperSize) {
		parent::__construct($pageOrientation, $unit,$paperSize);

		$this->setHtmlVSpace(array(
			"dt"=>array(0=>array("n"=>0)),
			"ul"=>array(0=>array("n"=>0)),
			"dd"=>array(0=>array("n"=>0))));		
	}

	private function applyNewFont($newFont) {
		$newFamily = getOptionalParam("family", $newFont, $this->getFontFamily());

		$newStyle = getOptionalParam("style", $newFont, $this->getFontStyle());

		$newSize = getOptionalParam("size", $newFont, $this->getFontSizePt());

		$this->SetFont($newFamily, $newStyle, $newSize);
	}

	private function addTextItem($textItem, $idx) {
		$text = getRequiredParam("text", $textItem, $idx);
		
		$align = getOptionalParam("align", $textItem, "L");

		$newLine = getOptionalParam("newLine", $textItem, true);
		$newLine = $newLine ? 1 : 0;

		$height = $this->getLineHeight($this);
		// We decode the string as in PDFs content UTF8 is not supported

		$text= str_replace("%PAGE_NUMBER%",$this->getAliasNumPage(),$text);
		$text=str_replace("%PAGE_COUNT%",$this->getAliasNbPages(), $text);

		$this->Cell(0, $height, $text, 0, $newLine, $align);

	}

	private function addParItem($parItem, $idx) {
		$text = getRequiredParam("text", $parItem, $idx);
		$align = getOptionalParam("align", $parItem, "L");
		$width = getOptionalParam("width", $parItem, 0);

		$lineHeight = $this->getLineHeight($this);

		$text= str_replace("%PAGE_NUMBER%",$this->getAliasNumPage(),$text);
		$text=str_replace("%PAGE_COUNT%",$this->getAliasNbPages(), $text);

		$this->MultiCell($width, $lineHeight, $text, 0, $align);
	}

	private function addImageItem($imageItem, $idx) {
		$pngImage = null;

		if (array_key_exists("url", $imageItem)) {

			// Image url can be a file in the server's filesystem, an url, or a data uri.
			$imageURL = $imageItem["url"];

			if (strpos($imageURL, "data:") === 0) {
				$pngImage = $this->imageFromDataUri($imageURL, $idx);
			} else {
				// We try retrieving a remote image.
				$pngImage = $this->imageFromRemoteUrl($imageURL, $idx);
			}

		} else if (array_key_exists("fileInputName", $imageItem)) {
			// The file came as an uploaded file in an multipart post request.
			$fileInputName = $imageItem["fileInputName"];
			$pngImage = $this->imageFromUpload($fileInputName, $idx);
		} else {
			showError("Either 'url' or 'fileInputName' must be specified for imageItem at position $idx");
		}

		// We retrieve the image's size.
		$imageWidthPx = imagesx($pngImage);
		$imageHeightPx = imagesy($pngImage);

		$dpi = getOptionalParam("dpi",$imageItem, 91);

		// Conversion between mm and px. 1 inch = 25.4 mm, and standard PDF resolution is 72 dpi (px/inch).
		$pxToMM = 25.4 / $dpi;

		error_log($imageWidthPx.", ".$imageHeightPx);
		$iWidth = $imageWidthPx * $pxToMM;
		$iHeight = $imageHeightPx * $pxToMM;

		// Optional params "width" and "height"	
		$width = $iWidth;
		$height = $iHeight;

		if (array_key_exists("width", $imageItem) && array_key_exists("height", $imageItem)) {
			$width = $imageItem["width"];
			$height = $imageItem["height"];
		} else if (array_key_exists("width", $imageItem)) {
			// If only one param is specified, we keep the aspect ratio.
			$width = $imageItem["width"];
			$height = $width * $iHeight / $iWidth;
		} else if (array_key_exists("height", $imageItem)) {
			$height = $imageItem["height"];
			$width = $height * $iWidth / $iHeight;
		}

		// We save the image in a tmp file to be able to load it.
		$imagePath = tempnam(sys_get_temp_dir(), "imageTmp");

		imagealphablending($pngImage, false);
		imagesavealpha($pngImage, true);
		imagepng($pngImage, $imagePath, 1);

		// Page break disabled before adding the image, or it might be downscaled to fit the page even if
		// the Image method receives a fitonpage false by default.
		$bottomMargin = $this->getBreakMargin();
		$this->SetAutoPageBreak(false);
		// We specify PNG as the format as we always convert the image or PDF to PNG.

		

		$scaleMode = 2;
		$dpi=300;
		if($width!=$iWidth || $height!= $iHeight) {
			$scaleMode = false;
			$dpi=0;
		}

		$x = $this->GetX();
		$y = $this->GetY();

		$this->Image($imagePath, $this->GetX(), $this->GetY(), $width, $height, "PNG","","",$scaleMode,$dpi);

		$border = getOptionalParam("border", $imageItem, false);
		if($border===true) {
			$border = array('LTRB' => array('width' => 0.1, 'cap' => 'square', 'join' => 'mitter', 'dash' => 0, 'color' => array(0, 0, 0)));
		} else if($border) {
			$borderColor = getOptionalParam("color", $border, "black");
			$borderWidth = getOptionalParam("width", $border, 1);
			$border = array('LTRB' => array('width' => $borderWidth, 'cap' => 'butt', 'join' => 'miter', 'dash' => 0, 'color' => $borderColor));
		}

		if($border) {
			$this->Rect($x, $y, $width, $height,"", $border, null);	
		}

		

		$this->SetAutoPageBreak(true, $bottomMargin);
		$this->SetY($this->GetY() + $height);

		unlink($imagePath);

		imagedestroy($pngImage);
	}

	private function imageFromDataUri($imageURL, $idx) {
		// We get the image's content.
		$imgData = base64_decode(substr($imageURL, strpos($imageURL, ",") + 1));

		$pngImage = $this->imageFromContents($imgData, $idx);
		if (!$pngImage) {
			showError("The data uri provided as url parameter for image item at position $idx doesn't contain a valid image or PDF.");
		}

		return $pngImage;
	}

	private function imageFromRemoteUrl($url, $idx) {

		$imageContent = $this->remoteRequest($url, $idx);

		// Handle response
		$srcImage = $this->imageFromContents($imageContent);


		if (!$srcImage) {
			showError("The url specified for image item at position $idx didn't contain a valid image.");
		}

		return $srcImage;
	}

	private function remoteRequest($url, $idx) {
		// Defining the default CURL options
		// $defaults = array(
		// CURLOPT_URL => $url,
		// CURLOPT_CONNECTTIMEOUT=>0,
		// CURLOPT_FRESH_CONNECT=>TRUE,
		// CURLOPT_RETURNTRANSFER => TRUE);

		// // Open the Curl session
		// $session = curl_init();

		// // Setting the options
		// curl_setopt_array($session, $defaults);

		// // Make the call
		// $remoteContent = curl_exec($session);

		$remoteContent = file_get_contents($url);


		if (!$remoteContent) {
			showError("Curl couldn't retrieve the content of url ".$url.
			" specified in the item at position $idx.: ");
		}

		// // Close the connetion
		// curl_close($session);
		
		return $remoteContent;
	}

	private function imageFromUpload($formFieldName, $idx) {
		if (!array_key_exists($formFieldName, $_FILES)) {
			showError("No uploaded file found for file input name '$formFieldName' specified for item at position $idx");
		}

		$error = $_FILES[$formFieldName]["error"];

		 switch ($error) {
	        case UPLOAD_ERR_OK:
	            $error = false;
	            break;
	        case UPLOAD_ERR_INI_SIZE:
	            $error = 'The uploaded file exceeds the upload_max_filesize directive in php.ini.';
	            break;
	        case UPLOAD_ERR_FORM_SIZE:
	            $error = 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.';
	            break;
	        case UPLOAD_ERR_PARTIAL:
	            $error = 'The uploaded file was only partially uploaded.';
	            break;
	        case UPLOAD_ERR_NO_FILE:
	            $error = 'No file was uploaded.';
	            break;
	        case UPLOAD_ERR_NO_TMP_DIR:
	            $error = 'Missing a temporary folder. Introduced in PHP 4.3.10 and PHP 5.0.3.';
	            break;
	        case UPLOAD_ERR_CANT_WRITE:
	            $error = 'Failed to write file to disk. Introduced in PHP 5.1.0.';
	            break;
	        case UPLOAD_ERR_EXTENSION:
	            $error = 'File upload stopped by extension. Introduced in PHP 5.2.0.';
	            break;
	        default:
	            $error = 'Unknown error';
	            break;
	    }

	    if($error) {
	    	showError("The image upload for field '$formFieldName' for image item at $idx failed: $error");
	    }


	    $fileName = $_FILES[$formFieldName]["name"];

		// We try opening the uploaded file we don't trust mime type or extensions.
		//$filePath = $_FILES[$formFieldName]["tmp_name"];			
		
		$filePath = sys_get_temp_dir()."/".$fileName;

		$fileContents = file_get_contents($filePath);

		if(!$fileContents) {
			showError("The image uploaded in field '$formFieldName' for image item at $idx is empty, path: $filePath, filename: $fileName");
		}

		$resultImg = $this->imageFromContents($fileContents);

		if (!$resultImg) {
			showError("The image uploaded in field '$formFieldName' for image item at $idx has an invalid format.");
		}

		return $resultImg;
	}

	private function imageFromContents($fileContents) {
		$img = @imagecreatefromstring($fileContents);


		if (!$img) {
			// We try converting this from pdf.
			$tmpPDFInput = tempnam(sys_get_temp_dir(), "pdfInput");
			file_put_contents($tmpPDFInput, $fileContents);


			$img = convertPDFFileToImage($tmpPDFInput);

			unlink($tmpPDFInput);
		}
		return $img;
	}


	private function addTableItem($tableItem, $idx) {

		$rows = getRequiredParam("rows", $tableItem, $idx);

		$left = $this->GetX();
		$top = $this->GetY();

		$this->setDrawColor(0, 0, 0);

		$borderWidth = $this->GetLineWidth();

		$cellPadding = getOptionalParam("cellpadding", $tableItem, 2);

		$htmlTable = "<table border=\"$borderWidth\" cellpadding=\"$cellPadding\">";

		$vAlignHeightHack = $this->GetFontSize() * 2;

		for ($rowIdx = 0; $rowIdx < count($rows); $rowIdx++) {
			$htmlTable.= "<tr>";

			$row = $rows[$rowIdx];
			for ($colIdx = 0; $colIdx < count($row); $colIdx++) {
				$column = $row[$colIdx];

				$colSpan = 1;
				$rowSpan = 1;
				$hAlign = "left";
				$vAlign = "top";
				$columnText = $column;
				$width = "auto";

				if (is_array($column)) {
					if (!array_key_exists("text", $column)) {
						showError("'text' must be defined for the cell $cIdx of row $rIdx of tableItem at position $idx");
					}
					$columnText = $column["text"];

					$colSpan = getOptionalParam("colspan", $column, 1);
					$rowSpan = getOptionalParam("rowspan", $column, 1);
					$hAlign = getOptionalParam("align", $column, "left");
					$vAlign = getOptionalParam("valign", $column, "top");
					$width = getOptionalParam("width", $column, "auto");
				}

				if ($rowSpan > 1) {
					switch ($vAlign) {
						case 'bottom':
							$columnText = "<span style=\"font-size: $vAlignHeightHack;\">".str_repeat('&nbsp;<br/>', $rowSpan).
							'</span>'.$columnText;
							break;
						case 'middle':
							$columnText = "<span style=\"font-size: $vAlignHeightHack;\">".str_repeat('&nbsp;<br/>', $rowSpan - 1).
							'</span>'.$columnText;
							break;
					}
				}

				if ($width === "auto") {
					$htmlTable.= "<td align=\"$hAlign\" colspan=\"$colSpan\" rowspan=\"$rowSpan\">$columnText</td>";
				} else {
					$width.= "mm";
					$htmlTable.= "<td align=\"$hAlign\" colspan=\"$colSpan\" rowspan=\"$rowSpan\" width=\"$width\">$columnText</td>";
				}

			}

			$htmlTable.= "</tr>";
		}

		$htmlTable.= "</table>";

		$htmlTable= str_replace("%PAGE_NUMBER%",$this->getAliasNumPage(),$htmlTable);
		$htmlTable=str_replace("%PAGE_COUNT%",$this->getAliasNbPages(), $htmlTable);

		$this->writeHTML($htmlTable, false, false, false, false, '');
	}

	private function addHtmlItem($htmlItem, $idx) {
		$htmlContent = getOptionalParam("content", $htmlItem, "");

		if (!$htmlContent) {
			$url = getOptionalParam("url", $htmlItem, false);
			if (!$url) {
				showError("Either 'url' or 'content' must be specified for the html item at position $idx");
			}

			$htmlContent = $this->remoteRequest($url, $idx);
		}


		$this->setListIndentWidth(getOptionalParam("listIndent", $htmlItem, $this->GetStringWidth("x")));

		$htmlContent= str_replace("%PAGE_NUMBER%",$this->getAliasNumPage(),$htmlContent);
		$htmlContent=str_replace("%PAGE_COUNT%",$this->getAliasNbPages(), $htmlContent);


		$this->writeHTML($htmlContent, false, false, false, false, '');
	}

	private function addPageBreakItem($pageBreakItem, $idx) {
		$this->AddPage();

		$columns = getOptionalParam("columns", $pageBreakItem,1);
		$columnTopMargin= getOptionalParam("topMargin", $pageBreakItem,1);

		if($columns>1){			
			$this->setEqualColumns($columns, ($this->getPageWidth()/$columns) -5, $columnTopMargin);	
		} else {
			$this->resetColumns();
		}
	}


	private function getLineHeight() {
		// A possibly very bad appoximation.
		return  $this->GetStringWidth("x")*1.5;
	}


	public function addItems($items) {

		for($i=0; $i < count($items); $i++) {
			$this->addItem($items[$i], $i);
		}
	}

	public function addItem($item, $idx) {
		// Here we do the common stuff.

		// We set the font styles that will be used in this items and following ones
		// (if not changed again).
		if (array_key_exists("newFont", $item)) {
			$this->applyNewFont($item["newFont"]);
		}

		$lineWidth = getOptionalParam("newLineWidth", $item, $this->GetLineWidth());
		$this->SetLineWidth($lineWidth);

		$type = getOptionalParam("type", $item, "text");


		$x = $this->GetX();
		if (array_key_exists("x", $item)) {
			// Absolute positioning.
			$x = intval($item["x"]);

		} else if (array_key_exists("dx", $item)) {
			// Relative
			$x += intval($item["dx"]);
		}


		$y = $this->GetY();
		if (array_key_exists("y", $item)) {
			// Absolute positioning.
			$y = intval($item["y"]);
		} else if (array_key_exists("dy", $item)) {
			// Relative
			$y += intval($item["dy"]);
		}

		$this->SetXY($x, $y);

		switch (strtolower($type)) {
			case "text":	
				$this->addTextItem($item, $idx);
				break;
			case "paragraph":
			case "par":
				$this->addParItem($item, $idx);
				break;
			case "image":
				$this->addImageItem($item, $idx);
				break;
			case "table":
				$this->addTableItem($item, $idx);
				break;
			case "html":
				$this->addHtmlItem($item, $idx);
				break;
			case "pagebreak":
				$this->addPageBreakItem($item, $idx);
				break;
			default:
				showError("Unsupported item type for item ".$idx.": ".$type);
		}

		// By default, we advance the position so we are placed after the element.
		$keepPosition = getOptionalParam("keepPosition", $item, false);
		if ($keepPosition) {
			// We keep the initial position, so we reset the calculated x and y.
			$this->SetXY($x, $y);
		}

	}

	public function setCustomHeader($header) {
		if(!$header) {
			return;
		}

		$margin = getOptionalParam("margin", $header, 10);
		$this->setHeaderMargin($margin);
		
		$items = getOptionalParam("items", $header, false);
		if(!$items) {
			return;
		}

		$this->headerItems = $items;
	}

	public function setCustomFooter($footer) {
		if(!$footer) {
			return;
		}

		$margin = getOptionalParam("margin", $footer, 10);
		$this->setFooterMargin($margin);

		$items = getOptionalParam("items", $footer, false);
		if(!$items) {
			return;
		}

		$this->footerItems = $items;	
	}

	public function Header() {
		if($this->headerItems && count($this->headerItems)>0) {
			$this->addItems($this->headerItems);	
		}
		
	}

	public function Footer() {
		if($this->footerItems && count($this->footerItems)>0) {
			$this->addItems($this->footerItems);		
		}
	}

	protected function setTableHeader() {
		if ($this->num_columns > 1) {
			// multi column mode
			return;
		}
		if (isset($this->theadMargins['top'])) {
			// restore the original top-margin
			$this->tMargin = $this->theadMargins['top'];
			$this->pagedim[$this->page]['tm'] = $this->tMargin;
			$this->y = $this->tMargin;
		}
		if (!TCPDF_STATIC::empty_string($this->thead) AND (!$this->inthead)) {
			// set margins
			$prev_lMargin = $this->lMargin;
			$prev_rMargin = $this->rMargin;

			error_log("1. ".$this->_theaderLeft);
			if($this->theadMargins["lmargin"]!= $this->lMargin) {
				$this->_theaderLeft = $this->lMargin;
			}

			$prev_cell_padding = $this->cell_padding;
			$this->lMargin = $this->theadMargins['lmargin'] + ($this->pagedim[$this->page]['olm'] - $this->pagedim[$this->theadMargins['page']]['olm']);
			$this->rMargin = $this->theadMargins['rmargin'] + ($this->pagedim[$this->page]['orm'] - $this->pagedim[$this->theadMargins['page']]['orm']);
			$this->cell_padding = $this->theadMargins['cell_padding'];
			if ($this->rtl) {
				$this->x = $this->w - $this->rMargin;
			} else {
				$this->x = $this->lMargin;
			}
			// account for special "cell" mode
			if ($this->theadMargins['cell']) {
				if ($this->rtl) {
					$this->x -= $this->cell_padding['R'];
				} else {
					$this->x += $this->cell_padding['L'];
				}
			}

			error_log("2. ".$this->_theaderLeft);
			$this->x = $this->lMargin;


			// print table header
			$this->writeHTML($this->thead, false, false, false, false, '');
			// set new top margin to skip the table headers
			if (!isset($this->theadMargins['top'])) {
				$this->theadMargins['top'] = $this->tMargin;
			}
			// store end of header position
			if (!isset($this->columns[0]['th'])) {
				$this->columns[0]['th'] = array();
			}
			$this->columns[0]['th']['\''.$this->page.'\''] = $this->y;
			$this->tMargin = $this->y;
			$this->pagedim[$this->page]['tm'] = $this->tMargin;
			$this->lasth = 0;
			$this->lMargin = $prev_lMargin;
			$this->rMargin = $prev_rMargin;
			$this->cell_padding = $prev_cell_padding;
		}
	}
}