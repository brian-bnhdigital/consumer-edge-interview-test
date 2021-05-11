<?php

declare(strict_types=1);

// Display a message to the user that they need to install packages using composer
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
	die('Please run "composer install" to install the illuminate/database and php-curl-class/php-curl-class packages that are used in this script');
}

require __DIR__ . '/vendor/autoload.php';

use Curl\Curl;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Database_Manager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;

/**
 * A class to setup the database connections and tables used for this page
 */
class Database
{

	protected $database_name = 'consumers_edge_interview';
	protected $host = '127.0.0.1';
	protected $user = 'homestead';
	protected $password = 'secret';

	/**
	 * Run any necessary functions or scripts to initalize the database
	 * 
	 * @return null
	 */
	public function __construct()
	{
		if ($this->host == '' || $this->user == '' || $this->password == '' || $this->database_name == '') {
			die('Please provide the database connection details in the Database Class.');
		}

		// Setup a connection to the database
		$this->setup_database_connection();

		// Generate a database table if needed
		$this->create_database_tables();
	}

	/**
	 * Creates the necessary database table(s) if they do not exist
	 * 
	 * @return null
	 */
	function create_database_tables(): void
	{
		// Check if the table exists already returns a boolean
		if (!Database_Manager::schema()->hasTable('vehicles')) {
			// Creates a vehicles table
			Database_Manager::schema()->create('vehicles', function ($table) {

				$table->bigIncrements('id')->autoIncrement()->unique()->unsigned();
				$table->string('make')->nullable();
				$table->integer('mileage')->nullable()->unsigned();
				$table->string('model')->nullable();
				$table->integer('price')->nullable()->unsigned();
				$table->integer('vehicle_id')->unique()->unsigned();
				$table->string('vin')->index()->unique();
				$table->index('id');
				$table->index('vehicle_id');
				$table->timestamps();
			});
			// Tables have been generated
		} else {
			// Tables have already been generated
		}
	}

	/**
	 * Sets up the database connection
	 * 
	 * @return null
	 */
	function setup_database_connection(): void
	{
		// Setup a database Manager class
		$database = new Database_Manager;

		// Add a mysql connection
		$database->addConnection([
			'driver'    => 'mysql',
			'host'      => $this->host,
			'database'  => $this->database_name,
			'username'  => $this->user,
			'password'  => $this->password,
			'charset'   => 'utf8',
			'collation' => 'utf8_unicode_ci',
			'prefix'    => '',
		]);

		// Set the event dispatcher used by Eloquent models
		$database->setEventDispatcher(new Dispatcher(new Container));

		// Make this Database instance available globally via static methods
		$database->setAsGlobal();

		// Setup the Eloquent ORM...
		$database->bootEloquent();

		// Database Connection has been created
	}
}

/**
 * A class to represent vehicles that have been found on carvana
 */
class Vehicle extends Model
{
	private $carvana_api_url = 'https://apim.carvana.io/search-api/api/v1/search/search';
	private $default_search_parameters = array(
		'pagination' => array(
			'page' => 1,
			'pageSize' => 20,
		),
	);
	protected $fillable = array(
		'make',
		'mileage',
		'model',
		'price',
		'vehicle_id',
		'vin'
	);
	private $last_vehicle_from_save = NULL; // To be used to transfer the vehicle from save_carvana inventory
	protected $table = 'vehicles';

	/**
	 * Fetch a single page of vehicles from carvana
	 * 
	 * @param int $page_id the page id to pull
	 * @return an array of vehicle records
	 */
	function fetch_carvana_inventory_by_page(int $page_id): array
	{
		// Setup a new curl request
		$curl = new Curl();

		// Set the headers for the curl
		$curl->setHeaders(array(
			'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:88.0) Gecko/20100101 Firefox/88.0',
			'Accept' => '*/*',
			'Accept-Language' => 'en-US,en;q=0.5',
			'Content-Type' => 'application/json',
			'Origin' => 'https://www.carvana.com',
			'Connection' => 'keep-alive',
			'Referer' => 'https://www.carvana.com/',
			'Pragma' => 'no-cache',
			'Cache-Control' => 'no-cache',
		));

		// Submit the post request with the page_id that is passed to this function
		$curl->post($this->carvana_api_url, array_merge($this->default_search_parameters, array(
			'pagination' => array(
				'page' => $page_id
			)
		)));

		// Setup an empty array of vehicles
		$response = array();

		// If an error occures while curling display the error
		if ($curl->error) {
			echo 'Error: ' . $curl->errorCode . ': ' . $curl->errorMessage . PHP_EOL;
			print_r($curl);
		} else {
			// Set the response to the vehicles that were found in the inventory response
			if (isset($curl->response) && isset($curl->response->inventory) && isset($curl->response->inventory->vehicles)) {
				$response = $curl->response->inventory->vehicles;
			} else {
				echo 'Error: curl response is invalid';
				print_r($curl);
			}
		}

		// Manual clean up.
		$curl->close();

		// Return the response array of vehicles. If the array is empty then something went wrong.
		return $response;
	}

	/**
	 * Persist a single vehicle record to the database
	 * 
	 * Since the interview test askes for this function to return a boolean 
	 * the vehicle found or created is stored in a temporary class variable 
	 * called "last_vehicle_from_save"
	 * 
	 * @param array $vehicle
	 * @return a boolean indicating success or failure
	 */
	function save_carvana_inventory(array $vehicle): bool
	{
		// firstOrCreate:: checks and verifis that a vehicle has not been created 
		// by doing a select statement on the vehicle_id
		// if none exists then a new record is entered with the merger of the first 
		// and second arrays

		$vehicle = Vehicle::firstOrCreate(array(
			'vehicle_id' => $vehicle['vehicleId']
		), array(
			'make' => $vehicle['make'],
			'mileage' => $vehicle['mileage'],
			'model' => $vehicle['model'],
			'price' => $vehicle['price']['total'],
			'vehicle_id' => $vehicle['vehicleId'],
			'vin' => $vehicle['vin']
		));

		// Temporarily save this vehicle in the class to be used later on
		$this->last_vehicle_from_save = $vehicle;

		// Determine if the vehicle was recently created ie. entered into the database
		// If true: this is a new vehicle else this vehicle already exists
		return $vehicle->wasRecentlyCreated;
	}

	/**
	 * Retrieve, loop through, and save all vehicles given a page id
	 * 
	 * @param int $page_id id of the page that will be looked at
	 * @return array/object of a success / failure if all vehicles have been saved into the database
	 */
	public function save_carvana_vehicle_inventory(int $page_id = 1): array
	{
		// Setup a results array to be displayed at the end
		$result = array(
			'existing_vehicles_found' => array(),
			'message' => '',
			'new_vehicles_added' => array(),
			'success' => FALSE
		);
		// Set a flag to determine if there are all new vehicles.
		$new_vehicles_found = FALSE;

		// Loop through the results of the fetch_carvana_inventory_by_page to save the individual vehicle
		foreach ($this->fetch_carvana_inventory_by_page($page_id) as $_vehicle) {

			// Since the scope of the project requires the _vehicle object to an array convert it to one
			$_vehicle = json_decode(json_encode($_vehicle), TRUE);

			// Save the vehicle in the database using the save_carvana_inventory function
			$new_vehicle = $this->save_carvana_inventory($_vehicle);

			// Determine if the vehicle has been saved into the database
			if (!$new_vehicle) {
				// Keep track of the existing vehicles
				$result['existing_vehicles_found'][] = $this->last_vehicle_from_save;
			} else {
				$new_vehicles_found = TRUE;
				// Keep track of the new vehicles as well
				$result['new_vehicles_added'][] = $this->last_vehicle_from_save;
			}
		}
		// If there are any already saved vehicles then we consider this an unsuccessful pull and display that in the message
		if (!$new_vehicles_found) {
			$result['message'] = 'No new vehicles were found';
		} else {
			$result['message'] = 'New vehicles were added to the database';
			$result['success'] = TRUE;
		}

		// Return the results
		return $result;
	}
}

// Setup the database connection and table(s) (if initally ran)
new Database();

// Setup a new vehical class to be able to interact with it
$vehicle = new Vehicle();

$page = 2;
// Setup the page to pull... You can pass a page in the url to see a different page of results.
if (isset($_GET['page']) && is_numeric($_GET['page'])) {
	$page = intval($_GET['page']);
}

// Retieve the desired page of vehical inventory and save all of them into the database
$result = $vehicle->save_carvana_vehicle_inventory($page);

?>
<html>

<head>
	<title>Consumers Edge Interview Test</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-wEmeIV1mKuiNpC+IOBjI7aAzPcEZeedi5yW5f2yOq55WWLwNGmvvx4Um1vskeMj0" crossorigin="anonymous">

	<link rel="stylesheet" type="text/css" href="https://fonts.googleapis.com/css?family=Quicksand" />
	<link rel="stylesheet" type="text/css" href="https://warfares.github.io/pretty-json/css/pretty-json.css" />
	<script type="text/javascript" src="https://warfares.github.io/pretty-json/libs/jquery-1.11.1.min.js"></script>
	<script type="text/javascript" src="https://warfares.github.io/pretty-json/libs/underscore-min.js"></script>
	<script type="text/javascript" src="https://warfares.github.io/pretty-json/libs/backbone-min.js"></script>
	<script type="text/javascript" src="https://warfares.github.io/pretty-json/pretty-json-min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js" integrity="sha384-p34f1UUtsS3wqzfto5wAAmdvj+osOnFyQFpp4Ua3gs/ZVWx6oOypYoCJhGGScy+8" crossorigin="anonymous"></script>
	<style>
		.darkBkg{
			background-color: #55595c;
			color:#fff;
		}
		.album .row + .row{
			margin-top: 1rem;
		}
	</style>
	<script>
		$(document).ready(function() {
			new PrettyJSON.view.Node({
				el: $('#result'),
				data: <?php echo json_encode($result); ?>
			});
		});
	</script>
</head>

<body>
	<main>
		<section class="py-5 text-center container">
			<div class="row py-lg-5">
				<div class="col-lg-10 col-md-8 mx-auto">
					<h1 class="fw-light">Consumer Edge Interview Test</h1>
					<p class="lead text-muted">Thank you for allowing me to perform this test. Below is a quick description of the results.</p>
				</div>
			</div>
		</section>
		<div class="album py-5 bg-light">
			<div class="container">
				<div class="row row-cols-1 row-cols-lg-1 g-1 g-lg-1">
					<div class="col">
						<div class="card">
							<div class="card-header darkBkg">
								Results:
							</div>
							<div class="card-body">
								<p class="card-text">
									If the script found new vehicles not in the database they will be displayed in the "new_vehicles_added" array and success response will be true.
									<br />
									Any vehicles that were already in the database are in the "existing_vehicles_found" array.
								</p>
								<span id="result"></span>
							</div>
						</div>
					</div>
				</div>
				<div class="row row-cols-1 row-cols-lg-2 gy-3 gx-4">
					<div class="col">
						<div class="card shadow-sm">
							<div class="card-header darkBkg">
								<div class="card-text">
									Database Schema:
								</div>
							</div>
							<div class="card-body">
								<p class="card-text">The create schema that is generated using the "illuminate/database" package is:</p>
								<div class="d-flex justify-content-between align-items-center">
									<p class="">
										<span class="text-primary">CREATE DATABASE IF NOT EXISTS</span> <span class="text-danger">`consumers_edge_interview`</span>;
									</p>
								</div>
							</div>
						</div>
					</div>
					<div class="col">
						<div class="card shadow-sm">
							<div class="card-header darkBkg">
								<div class="card-text">
									Table Schema:
								</div>
							</div>
							<div class="card-body">
								<p class="card-text">The table schema that is generated with "illuminate/database" package is:</p>
								<div class="d-flex justify-content-between align-items-center">
									<p class="">
										<span class="text-primary">CREATE TABLE</span> <span class="text-danger">`vehicles`</span> (<br />
										<span class="text-danger">`id`</span> bigint unsigned <span class="text-primary">NOT NULL AUTO_INCREMENT</span>,<br />
										<span class="text-danger">`make`</span> <span class="text-primary">varchar</span>(<span class="text-warning">255</span>) <span class="text-primary">COLLATE</span> utf8_unicode_ci <span class="text-primary">DEFAULT NULL</span>,<br />
										<span class="text-danger">`mileage`</span> <span class="text-primary">int unsigned DEFAULT NULL</span>,<br />
										<span class="text-danger">`model`</span> <span class="text-primary">varchar</span>(<span class="text-warning">255</span>) <span class="text-primary">COLLATE</span> utf8_unicode_ci <span class="text-primary">DEFAULT NULL</span>,<br />
										<span class="text-danger">`price`</span> <span class="text-primary">int unsigned DEFAULT NULL</span>,<br />
										<span class="text-danger">`vehicle_id`</span> <span class="text-primary">int unsigned NOT NULL</span>,<br />
										<span class="text-danger">`vin`</span> <span class="text-primary">varchar</span>(<span class="text-warning">255</span>) <span class="text-primary">COLLATE</span> utf8_unicode_ci <span class="text-primary">NOT NULL</span>,<br />
										<span class="text-danger">`created_at`</span> <span class="text-primary">timestamp NULL DEFAULT NULL</span>,<br />
										<span class="text-danger">`updated_at`</span> <span class="text-primary">timestamp NULL DEFAULT NULL</span>,<br />
										<span class="text-primary">PRIMARY KEY</span> (<span class="text-danger">`id`</span>),<br />
										<span class="text-primary">UNIQUE KEY</span> <span class="text-danger">`vehicles_id_unique`</span> (<span class="text-danger">`id`</span>),<br />
										<span class="text-primary">UNIQUE KEY</span> <span class="text-danger">`vehicles_vehicle_id_unique`</span> (<span class="text-danger">`vehicle_id`</span>),<br />
										<span class="text-primary">UNIQUE KEY</span> <span class="text-danger">`vehicles_vin_unique`</span> (<span class="text-danger">`vin`</span>),<br />
										<span class="text-primary">KEY <span class="text-danger">`vehicles_id_index`</span> (<span class="text-danger">`id`</span>),<br />
										<span class="text-primary">KEY <span class="text-danger">`vehicles_vehicle_id_index`</span> (<span class="text-danger">`vehicle_id`</span>)<br />
										) <span class="text-primary">ENGINE</span>=InnoDB <span class="text-primary">DEFAULT CHARSET</span>=utf8 <span class="text-primary">COLLATE</span>=utf8_unicode_ci;
									</p>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</main>
</body>
</html>