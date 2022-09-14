<?php



function depersp($imageName, $debug = TRUE){


	// Line intercept math by Paul Bourke http://paulbourke.net/geometry/pointlineplane/
	// Determine the intersection point of two line segments
	// Return FALSE if the lines don't intersect
	function intersect($x1, $y1, $x2, $y2, $x3, $y3, $x4, $y4) {

		// Check if none of the lines are of length 0
		if (($x1 === $x2 && $y1 === $y2) || ($x3 === $x4 && $y3 === $y4)) {
			return false;
		}

		$denominator = (($y4 - $y3) * ($x2 - $x1) - ($x4 - $x3) * ($y2 - $y1));
	/*
		// Lines are parallel
		if ($denominator === 0) {
				echo "<pre>";
					print_r("Parallel lines!");
				echo "<pre>";
			return false;
		}
	*/
		$ua = (($x4 - $x3) * ($y1 - $y3) - ($y4 - $y3) * ($x1 - $x3)) / $denominator;
		$ub = (($x2 - $x1) * ($y1 - $y3) - ($y2 - $y1) * ($x1 - $x3)) / $denominator;
	/*
		// is the intersection along the segments
		if ($ua < 0 || $ua > 1 || $ub < 0 || $ub > 1) {
				print_r("intersection along the segments");
				return fa*se;
		}
	*/
		// Return a object with the x and y coordinates of the intersection
		$x = $x1 + $ua * ($x2 - $x1);
		$y = $y1 + $ua * ($y2 - $y1);

		return [$x, $y];
	}

	if( !file_exists($imageName) ){
		print_r('non esiste il file!');
	}

	exec(dirname( __FILE__ )."/A-discoverEdges.sh $imageName", $output, $retval);

	echo "<h1>Discover</h1>";
	echo "<pre>";
		print_r($output);
	echo "<pre>";


	$lsCrds = [];

	$lineNumber = 0;
	foreach ($output as $line => $lvalue) {


		if (strpos($lvalue, ':') === FALSE){

			if (strpos($lvalue, 'line') !== FALSE){
			
				$str_arr = explode (" ", $lvalue);
				
				$line=[];
				
				$pA=explode (",", $str_arr[1]);
				$pB=explode (",", $str_arr[2]);
				
				$line["a"][0] = floatval($pA[0]);
				$line["a"][1] = floatval($pA[1]);
				$line["b"][0] = floatval($pB[0]);
				$line["b"][1] = floatval($pB[1]);
				
				$line["angle"] = $str_arr[6];
				
				$lsCrds[] = $line;
							
			}
		}
		
		$lineNumber++;
	}


		echo "<h1>Lines</h1>";
		echo "<pre>";
			print_r($lsCrds);
		echo "<pre>";

		//We can't find a solution
	if (count($lsCrds) !== 4) {
			#echo "<h1>Ah, noooo, error!</h1><p>Cannot find 4 lines...</p>";
			#print_r($output);
			return $imageName;
	}



	#Discover intersections
	//For each segment find all intersections, sort of cartesian product, and discard the ones outside the sheet
	$points = [];

	foreach ($lsCrds as $l => $lcoords) {

		foreach ($lsCrds as $m => $lcoordss) {

			//Skip self intersection check
			if ($l !== $m) {
			
				$comb = [$l, $m];
				sort($comb); //Do not make difference between 12 and 21
				$duet = implode( "", $comb ); //A numerical "dittongo"


				//Do not compute intesection twice
				if (! array_key_exists($duet, $points) ) {
				
			
					$r1 = $lsCrds[$l];
					$r2 = $lsCrds[$m];
					
					$int = intersect( $r1["a"][0], $r1["a"][1], $r1["b"][0], $r1["b"][1], $r2["a"][0], $r2["a"][1], $r2["b"][0], $r2["b"][1]);
					
					if ( $int[0] >= 0 && $int[0] <= 1024 && $int[1] >= 0 && $int[1] <= 1024 ) {
							$points[$duet] = $int;
					}
					  
				}
			


			}	

		}  
	}



	#Sorting in order

	// Define the custom sort functions
	function custom_sort_x($a,$b) {
		  return $a[0]>$b[0];
	}

	function custom_sort_y($a,$b) {
		  return $a[1]>$b[1];
	}

	// Sort the multidimensional array
	usort($points, "custom_sort_x");

	$leftCol = [$points[0],$points[1]];
	$rightCol = [$points[2],$points[3]];

	usort($leftCol, "custom_sort_y");
	usort($rightCol, "custom_sort_y");


	$topLeft = $leftCol[0];
	$bottomLeft = $leftCol[1];
	$topRight = $rightCol[0];
	$bottomRight = $rightCol[1];
		/*
		echo "<h1>Corner assignment</h1>";
	echo "-->topLeft";
	print_r($topLeft); # to 0,0
	echo "-->bottomLeft";
	print_r($bottomLeft); # to 0,1024
	echo "-->topRight";
	print_r($topRight); # to 1024,0
	echo "-->bottomRight";
	print_r($bottomRight); # to 1024,1024
		*/

		/*
		#We don't know image proportions, let's calculate a distance
		$dist1 = sqrt(pow(($topLeft[1]-$bottomLeft[1]),2)+pow(($bottomRight[0]-$bottomLeft[0]),2));

	/*
	#Courtesy of https://stackoverflow.com/questions/21060859/returning-the-next-nearest-power-of-2-for-the-given-integer-number
	function next_pow($num){
		  if(is_numeric($num)){
		      if($num > 1){
		      return pow(2, ceil(log($num, 2)));
		      }
		      else{
		      return 1;
		      }
		  }
	return false;
	}


	echo "Should be px";
	print_r(next_pow((int) $dist1));
	echo "Or";
	print_r(next_pow((int) 64));
	*/

	$distortion = implode(",", $topLeft)." 0,0 ";
	$distortion .= implode(",", $bottomLeft)." 0,1024 ";
	$distortion .= implode(",", $topRight)." 1024,0 ";
	$distortion .= implode(",", $bottomRight)." 1024,1024";


	#NB. bash interpret this list as 8 arguments, space separated
	#echo "<h1>Magick command</h1>";
	exec(dirname( __FILE__ )."/B-undistort.sh $imageName $distortion", $result, $retval);

	#echo "Result is: ";

	return $result[0];


}

?>
