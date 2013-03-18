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
	$newFamily = getOptionalParam("family",$newFont,$pdf->FontFamily);
	
	$newStyle = getOptionalParam("style",$newFont,$pdf->FontStyle);
	
	$newSize = getOptionalParam("size",$newFont,$pdf->FontSize);

	$pdf->SetFont($newFamily, $newStyle, $newSize);
}

function addTextItem($pdf, $textItem, $idx) {
	$text = getRequiredParam("text",$textItem,$idx);

	$align = getOptionalParam("align",$textItem,"L");
	
	$newLine = getOptionalParam("newLine",$textItem,true);
	$newLine = $newLine?1:0;

	$height = getLineHeight($pdf);
	// We decode the string as in PDFs content UTF8 is not supported
	$pdf->Cell(0, $height, utf8_decode($text),0,$newLine,$align);
}

function addParItem($pdf, $parItem, $idx) {
	$text = getRequiredParam("text",$parItem,$idx);
	$align = getOptionalParam("align",$parItem,"L");
	$width= getOptionalParam("width", $parItem, 0);

	$lineHeight = getLineHeight($pdf);
	$pdf->MultiCell($width,$lineHeight, $text, 0, $align);	
}

function addImageItem($pdf, $imageItem, $idx) {
	$pngImage = null;

	if(array_key_exists("url", $imageItem)) {

		// Image url can be a file in the server's filesystem, an url, or a data uri.
		$imageURL  = $imageItem["url"];

		if(strpos($imageURL,"data:")===0) {
			$pngImage = imageFromDataUri($imageURL, $idx);
		} else  {
			// We try retrieving a remote image.
			$pngImage = imageFromRemoteUrl($imageURL, $idx);
		}

	} else if(array_key_exists("fileInputName", $imageItem)) {
		// The file came as an uploaded file in an multipart post request.
		$fileInputName = $imageItem["fileInputName"];		
		$pngImage = imageFromUpload($fileInputName, $idx);
	} else {
		showError("Either 'url' or 'fileInputName' must be specified for imageItem at position $idx");
	}

	// We retrieve the image's size.
	$imageWidthPx = imagesx($pngImage);	
	$imageHeightPx = imagesy($pngImage);

	// Conversion between mm and px. 1 inch = 25.4 mm, and standard PDF resolution is 72 dpi (px/inch).
	$pxToMM = 25.4/72;

	$iWidth = $imageWidthPx*$pxToMM;
	$iHeight = $imageHeightPx*$pxToMM;
	
	// Optional params "width" and "height"	
	$width = $iWidth;
	$height = $iHeight;
	if(array_key_exists("width",$imageItem) && array_key_exists("height",$imageItem)) {
		$width = $imageItem["width"];
		$height = $imageItem["height"];
	} else if(array_key_exists("width",$imageItem)){
		// If only one param is specified, we keep the aspect ratio.
		$width = $imageItem["width"];
		$height = $width*$iHeight/$iWidth;
	} else if(array_key_exists("height",$imageItem)){
		$height = $imageItem["height"];
		$width = $height*$iWidth/$iHeight;
	} 
	
	// We save the image in a tmp file to be able to load it.
	$imagePath = tempnam(sys_get_temp_dir(),"imageTmp");

	imagealphablending($pngImage, false);
	imagesavealpha($pngImage, true);
	imagepng($pngImage, $imagePath, 1);

	// We specify PNG as the format as we always convert the image or PDF to PNG.
	$pdf->Image($imagePath, $pdf->GetX(), $pdf->GetY(), $width, $height, "PNG");

	$pdf->SetY($pdf->GetY()+$height);

	unlink($imagePath);

	imagedestroy($pngImage);
}

function imageFromDataUri($imageUrls, $idx) {
	// We get the image's content.
	$imgData = base64_decode(substr($imageURL, strpos($imageURL, ",")+1));

	$pngImage = imageFromContents($imgData, $idx);
	if(!$pngImage) {
		showError("The data uri provided as url parameter for image item at position $idx doesn't contain a valid image or PDF.");
	}

	return $pngImage;
}

function imageFromRemoteUrl( $url, $idx) {
	// Defining the default CURL options
	$defaults = array( 
	        CURLOPT_URL => $url, 
	        CURLOPT_RETURNTRANSFER => TRUE	  
	 ); 

	// Open the Curl session
	$session = curl_init();		    

	// Setting the options
	curl_setopt_array($session, $defaults);		    

	// Make the call
	$imgResp = curl_exec($session);	
	

	// Handle response
	$srcImage = null;
	if($imgResp) {
	    $srcImage = imageFromContents($imgResp);
	}  else {
		showError("Curl couldn't retrieve the image specified in the image item at position $idx. Error was: ".curl_error($session));
	}

	if(!$imgResp) {
		showError("The url specified for image item at position $idx didn't contain a valid image.");
	}

	// Close the connetion
	curl_close($session);

	return $srcImage;
}

function imageFromUpload($formFieldName, $idx) {
	if(!array_key_exists($formFieldName, $_FILES)) {
		showError("No uploaded file found for file input name '$fileInputName' specified for item at position $idx");
	}

	// We try opening the uploaded file we don't trust mime type or extensions.
	$filePath = $_FILES[$formFieldName]["tmp_name"];


	$resultImg = imageFromContents(file_get_contents($filePath));

	if(!$resultImg){
		showError("The image uploaded in field '$formFieldName' for image item at $idx has an invalid format.");
	}
	
	unlink($filePath);

	return $resultImg;

}

function imageFromContents($fileContents) {
	$img = @imagecreatefromstring($fileContents);


	if(!$img) {
		// We try converting this from pdf.
		$tmpPDFInput = tempnam(sys_get_temp_dir(), "pdfInput");
		file_put_contents($tmpPDFInput, $fileContents);

		
		$img = new imagick(); // [0] can be used to set page number

	   
	    $img->setResolution(175,175);	
	    $img->readImage($tmpPDFInput);
	        
	    $img->resetIterator();

	    $img = $img->appendImages(true);


	    $img->setImageFormat( "png" );

	    $img->setImageUnits(imagick::RESOLUTION_PIXELSPERINCH);
	    

	    $data = $img->getImageBlob(); 


	    $img = imagecreatefromstring($data);

		unlink($tmpPDFInput);
	}
	return $img;
}

function getFormatFromMimeType ($mimeType, $idx) {
	$barIdx = strpos($mimeType,"/");
	if($barIdx<=0) {
		showError("Mime type for uploaded file or data uri specified for imageItem at position $idx is not valid");
	}

	$format = substr($mimeType, $barIdx+1);
	return $format;
}



function addTableItem($pdf, $tableItem, $idx) {

	$widths = getRequiredParam("widths",$tableItem,$idx);
	$rows = getRequiredParam("rows", $tableItem, $idx);

	$borderWidth = getOptionalParam("borderWidth",$tableItem,0.3);

	$left = $pdf->GetX();
	$top = $pdf->GetY();

	$pdf->setLineWidth($borderWidth);
	$pdf->setDrawColor(0,0,0);



	$cellHeight = getLineHeight($pdf)+2;
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

	$type = getOptionalParam("type",$item,"text");


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


function getRequiredParam($paramName, $item, $itemIdx) {
	if(!array_key_exists($paramName, $item)) {
		showError("'$paramName' property needs to be specified for item at position $idx.");
	}

	return $item[$paramName];
}

function getOptionalParam ($paramName, $item, $defaultValue) {
	$result = $defaultValue;
	if(array_key_exists($paramName, $item)) {
		$result = $item[$paramName];
	}

	return $result;
}

function getLineHeight($pdf) {
	// A possibly very bad appoximation.
	return  $pdf->GetStringWidth("x")*1.5;
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