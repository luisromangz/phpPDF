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
		showError("'text' field must be specified for textItem ". $idx);
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


function addImageItem($pdf, $item, $idx) {
	showError("addParItem no yet implemented!");
}


function addTableItem($pdf, $item, $idx) {
	showError("addParItem no yet implemented!");
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

	if(array_key_exists("x", $item)) {
		$pdf->SetX($item["x"]);
	}

	if(array_key_exists("y", $item)) {
		$pdf->SetY($item["y"]);
	}

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
	$params = json_decode($params,true);
	if(!$params) {
		showError("Params parameter isn't valid JSON!");	
	}
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
	showError("At least one item must be defined!");
}

$pdf = new FPDF("P","mm",$paperSize);
$pdf->SetMargins($margin, $margin);
$pdf->AddPage();
$pdf->SetFont('Arial','B',16);
for($i=0; $i < count($items); $i++) {
	addItem($pdf, $items[$i], $i);
}

$pdf->Output();