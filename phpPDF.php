<?

require("lib/fpdf.php");

function showError($errMesg) {
	error_log("phpPDF: ".$errMesg);
	$response = array(
		"error" => "phpPDF: ".$errMesg);
	
	header("Content-Type: application/json");  
	echo stripslashes(json_encode($response));	
	exit(1);
}

function applyNewFont($pdf, $newFont) {
	$newFamily = $pdf->FontFamily;
	if(array_key_exists("family",$newFont)) {
		$newFamily=$newFont["family"];
	}

	$newStyle = $pdf->FontStyle;
	if(array_key_exists("style", $newFont)) {
		$newStyle = $newFont["style"];
	} 

	$newSize = $pdf->FontSize;
	if(array_key_exists("size", $newFont)) {
		$newSize = $newFont["size"];
	}

	$pdf->SetFont($newFamily, $newStyle, $newSize);
}

function addTextItem($pdf, $textItem, $idx) {
	if(!array_key_exists("text", $textItem)) {
		showError("'text' must be specified for textItem at position ". $idx);
	}
	$text = $textItem["text"];

	$align = "L";
	if(array_key_exists("align",$textItem)) {
		$align = $textItem["align"];
	}
	
	$newLine = 0;
	if(array_key_exists("newLine", $textItem)) {
		$newLine = $textItem["newLine"];
		if($newLine===true) {
			$newLine = 1;
		} else {
			$newLine = 0;
		}
	}

	$height = $pdf->GetStringWidth("x")*1.5;
	// We decode the string as in PDFs content UTF8 is not supported
	$pdf->Cell(0, $height, utf8_decode($text),0,$newLine,$align);
}

function addParItem($pdf, $item, $idx) {
	showError("addParItem no yet implemented!");
}


function addImageItem($pdf, $imageItem, $idx) {

	$format = null;
	$imageURL ="";
	$tmpFile = null;
	if(array_key_exists("url", $imageItem)) {

		// Image url can be a file in the server's filesystem, an url, or a data uri.
		$imageURL  = $imageItem["url"];

		if(strpos($imageURL,"data:")===0) {
			// We get a temporal file name and open the image.
			$tmpFile = tempnam(sys_get_temp_dir(),"phpPDFImage");

			// We get the image's content.
			$imgData = base64_decode(substr($imageURL, strpos($imageURL, ",")+1));

			$tmpImage = imagecreatefromstring($imgData);

			// The image is saved in the tmp file.
			imagepng($tmpImage, $tmpFile,0);

			// We set the temp file as the url so its used by the Image method.
			$imageURL = $tmpFile;

			// We can do this because we are saving the image ourselves.
			$format = "PNG";

		} else if(array_key_exists("format",$imageItem)) {
			// Optional param "format" when the url or file is specified.
			$format = $imageItem["format"];			
			if(!validImageFormat($format)) {
				showError("Invalid 'format' specified for imageItem at position $idx. Must be either PNG, JPEG or GIF");
			}
		}

	} else if(array_key_exists("fileInputName", $imageItem)) {

		// The file came as an uploaded file in an multipart post request.
		$fileInputName = $imageItem["fileInputName"];		
		if(!array_key_exists($fileInputName, $_FILES)) {
			showError("No uploaded file found for file input name '$fileInputName' specified for imageItem at position $idx");
		}

		$uploadedFile = $_FILES[$fileInputName];

		if($uploadedFile["error"]) {
			showError("An error happened while uploading the file specified for imageItem at position $idx: "
				. $uploadedFile["error"]);	
		}


		$imageURL = $uploadedFile["tmp_name"];

		$tmpFile = $imageURL;

		// We retrieve the format from the uploaded mime type.
		$format = getFormatFromMimeType($uploadedFile["type"]);

		if(!validImageFormat($format)) {
			showError("Mime type for uploaded file specified for imageItem at position $idx must be either image/png, image/jpeg or image/gif");
		}

	} else {
		showError("Either 'url' or 'fileInputName' must be specified for imageItem at position $idx");
	}

	// We retrieve the image's size.
	$imageSize = getimagesize($imageURL);	

	// Conversion between mm and px. 1 inch = 25.4 mm, and standard PDF resolution is 72 dpi (px/inch).
	$pxToMM = 25.4/72;

	$iWidth = $imageSize[0]*$pxToMM;
	$iHeight = $imageSize[1]*$pxToMM;
	
	// Optional params "width" and "height"	
	$width = $iWidth;
	$height = $iHeight;
	if(array_key_exists("width",$imageItem) && array_key_exists("height",$imageItem)) {
		$width = $imageItem["width"];
		$height = $imageItem["height"];
	} else if(array_key_exists("width",$imageItem)){
		// If only one param is specified, we keep the aspct ratio.
		$width = $imageItem["width"];
		$height = $width*$iHeight/$iWidth;
	} else if(array_key_exists("height",$imageItem)){
		$height = $imageItem["height"];
		$width = $height*$iWidth/$iHeight;
	} 
	

	$pdf->Image($imageURL, $pdf->GetX(), $pdf->GetY(), $width, $height, $format);

	$pdf->SetY($pdf->GetY()+$height);

	if($tmpFile) {
		// We delete any temporal file used.
		unlink($tmpFile);
	}
}

function getFormatFromMimeType ($mimeType, $idx) {
	$barIdx = strpos($mimeType,"/");
	if($barIdx<=0) {
		showError("Mime type for uploaded file or data uri specified for imageItem at position $idx is not valid");
	}

	$format = substr($mimeType, $barIdx+1);
	return $format;
}

function validImageFormat($format) {
	return strcasecmp($format, "PNG") || strcasecmp($format, "JPEG") || strcasecmp($format, "GIF");
}


function addTableItem($pdf, $tableItem, $idx) {

	error_log("neeeeee".$pdf->GetX());

	$widths = null;
	if(!array_key_exists("widths",$tableItem)) {
		showError("'widths' must be defined for the tableItem at position $idx");
	}

	$widths = $tableItem["widths"];

	if(!array_key_exists("rows", $tableItem)) {
		showError("'rows' must be defined for the tableItem at position $idx");
	}

	$rows = $tableItem["rows"];

	$borderWidth = 0.3;
	if(array_key_exists("borderWidth", $tableItem)) {
		$borderWidth = $tableItem["borderWidth"];
	}


	$left = $pdf->GetX();
	$top = $pdf->GetY();

	$pdf->setLineWidth($borderWidth);
	$pdf->setDrawColor(0,0,0);



	$cellHeight = $pdf->GetStringWidth("x")*1.5+2;
	for($rIdx = 0; $rIdx < count($rows); $rIdx++) {
		$row = $rows[$rIdx];		
		$rowColumnsCount = 0;

		for($cIdx=0; $cIdx< count($row); $cIdx++) {

			$column = $row[$cIdx];
			$columnWidth = $widths[$cIdx];

			if(is_numeric($column)) {
				// This cell was handled with a rowspan.
				// We must do nothing but to move the drawing position.	
				$pdf->SetX($pdf->GetX()+$column);
				continue;
			}

			$columnText = $column;
			
			$rowSpan = 1;

			$halign = "L";
			$valign = "T";

			if(is_array($column)) {
				if(!array_key_exists("text",$column)) {
					showError("'rows' must be defined for the cell $cIdx of row $rIdx of tableItem at position $idx");
				}
				$columnText = $column["text"];

				if(array_key_exists("colspan",$column)) {
					$colSpan = $column["colspan"];
					// Width is increased with the widths of next columns.
					for($csOffset = 1; $csOffset<= $colSpan-1; $csOffset++ ) {
						$columnWidth += $widths[$cIdx+ $csOffset];
					}
				}
				
				if(array_key_exists("rowspan",$column)) {

					// We insert entries in the position of the current column in the next rows
					// to indicate that we shouldn't draw a cell there.					
					$rowSpan = $column["rowspan"];

					for($rsOffset=1; $rsOffset<=$rowSpan-1; $rsOffset++) {
						// We get the row by reference so modifications are applied.
						$rsRow = &$rows[$rIdx+$rsOffset];

						// We insert the column's width so if colspan is applied,
						// we know how much we need to move.
						array_splice($rsRow,$cIdx,0, array($columnWidth));						
					}
				}

				if(array_key_exists("halign",$column)) {
					$halign = $column["halign"];
				}

				if(array_key_exists("valign",$column)) {
					$valign = $column["valign"];
				}
			}


			$cX = $pdf->GetX();
			$cY = $pdf->GetY();

			// We draw the border separatedly because want to do fancy valign things.
			$pdf->Rect($cX, $cY, $columnWidth, $cellHeight*$rowSpan);	

			if($valign=="M"){
				$pdf->Cell($columnWidth,$cellHeight* $rowSpan,$columnText,0, 0, $halign);		
			} else if($valign=="B"){
				$bottomPos = $cellHeight* ($rowSpan-1);
				$pdf->SetXY($cX,$cY+$bottomPos);
				$pdf->Cell($columnWidth,$cellHeight,$columnText,0, 0, $halign);
				$pdf->SetXY($pdf->GetX(),$cY);
			} else {
				// TOP align.
				$pdf->Cell($columnWidth,$cellHeight,$columnText,0, 0, $halign);		
			}
			

				
		}

		
		$pdf->SetXY($left, $pdf->GetY()+$cellHeight);
	}

}

function addItem ($pdf, $item, $idx) {
	// Here we do the common stuff.
	if(array_key_exists("newFont", $item)) {
		applyNewFont($pdf, $item["newFont"]);
	}

	$type = "text";
	if(array_key_exists("type", $item)) {
		$type = $item["type"];
	}


	$x = $pdf->GetX();
	if(array_key_exists("x", $item)) {
		// Absolute positioning.
		$x = intval($item["x"]);

	} else if(array_key_exists("dx", $item)) {
		// Relative
		$x+=intval($item["dx"]);
	}
	

	$y = $pdf->GetY();
	if(array_key_exists("y", $item)) {
		// Absolute positioning.
		$y = intval($item["y"]);
	} else if(array_key_exists("dy", $item)) {
		// Relative
		$y+=intval($item["dy"]);
	}

	$pdf->SetXY($x,$y);

	switch(strtolower($type)) {
		case "text":
			addTextItem($pdf, $item, $idx);
			break;
		case "paragraph":
		case "par":
		 	addParItem($pdf, $item, $idx);
			break;
		case "image":
			addImageItem($pdf, $item, $idx);
			break;
		case "table":
			addTableItem($pdf, $item, $idx);
			break;
		default:
			showError("Unsupported item type for item ". $idx);
	}

}

$params = null;


if(array_key_exists("params", $_GET)) {
	$params =$_GET["params"];
} else if(array_key_exists("params",$_POST)) {
	$params = $_POST["params"];
}

$response = array();

if($params) {
	// We decode the params into an associative array
	$decodedParams = json_decode($params,true);
	if(!$decodedParams) {
		showError("Params parameter isn't valid JSON!: ".$params);	
	}

	$params = $decodedParams;
} else {
	 showError("Params parameter is required!");
}



$paperSize = "A4";
if(array_key_exists("size", $params)) {
	$paperSize = $params["size"];
}

$margin = 30;
if(array_key_exists("margin", $params)) {
	$margin = $params["margin"];
}

$items = array();
if(array_key_exists("items",$params)) {


	$items = $params["items"];
} else {
	showError("At least one item must be defined! ".$params);
}



$pdf = new FPDF("P","mm",$paperSize);
$pdf->SetMargins($margin, $margin);
$pdf->AddPage();
$pdf->SetFont('Arial','',12);
for($i=0; $i < count($items); $i++) {
	addItem($pdf, $items[$i], $i);
}

$pdf->Output();