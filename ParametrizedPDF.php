<? require_once("lib/tcpdf/tcpdf.php");

class ParametrizedPDF extends TCPDF {

	protected $footerItems = array();
	protected $headerItems = array();
	private $_theaderLeft;

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

		// Conversion between mm and px. 1 inch = 25.4 mm, and standard PDF resolution is 72 dpi (px/inch).
		$pxToMM = 25.4 / 72;

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
		$this->Image($imagePath, $this->GetX(), $this->GetY(), $width, $height, "PNG");
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
		$defaults = array(
		CURLOPT_URL => $url,
		CURLOPT_RETURNTRANSFER => TRUE);

		// Open the Curl session
		$session = curl_init();

		// Setting the options
		curl_setopt_array($session, $defaults);

		// Make the call
		$remoteContent = curl_exec($session);

		// Close the connetion
		curl_close($session);

		if (!$remoteContent) {
			showError("Curl couldn't retrieve the content of url ".$url.
			" specified in the item at position $idx. Error was: ".curl_error($session));
		}

		return $remoteContent;
	}

	private function imageFromUpload($formFieldName, $idx) {
		if (!array_key_exists($formFieldName, $_FILES)) {
			showError("No uploaded file found for file input name '$fileInputName' specified for item at position $idx");
		}

		// We try opening the uploaded file we don't trust mime type or extensions.
		$filePath = $_FILES[$formFieldName]["tmp_name"];


		$resultImg = $this->imageFromContents(file_get_contents($filePath));

		if (!$resultImg) {
			showError("The image uploaded in field '$formFieldName' for image item at $idx has an invalid format.");
		}

		unlink($filePath);

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


		$htmlContent= str_replace("%PAGE_NUMBER%",$this->getAliasNumPage(),$htmlContent);
		$htmlContent=str_replace("%PAGE_COUNT%",$this->getAliasNbPages(), $htmlContent);


		$this->writeHTML($htmlContent, false, false, false, false, '');
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
			default:
				showError("Unsupported item type for item ".$idx);
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