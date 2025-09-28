var map;
var g_last_lat = 0.0;
var g_last_lon = 0.0;

var domain_name = window.location.hostname;
var api_address = 'https://' + domain_name + '/';

// Function to fetch flight data based on flight_id
async function fetchFlightData(flightId) {
    try {
        const response = await fetch(`${api_address}flights.php?flight_id=${flightId}`);
        const data = await response.json();
        return data;
    } catch (error) {
        console.error('Error fetching flight data:', error);
        return null;
    }
}

// Function to initialize the map and display the marker
function initMap(flightId, lastLatitude, lastLongitude) {
	if((lastLatitude == null) || (lastLongitude == null)){
		lastLatitude = 0.0;
		lastLongitude = 0.0;
	}
    map = L.map('map').setView([lastLatitude, lastLongitude], 15);
	
	var osmUrl = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
	var osmAttrib = '<a href=\"http://openstreetmap.org\">OpenStreetMap</a>';
	var osm = new L.TileLayer(osmUrl,{
		attribution: osmAttrib
	});
									
	var satelliteUrl = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
	var satelliteAttrib = 'Powered by <a href=\"https://www.esri.com/\">Esri</a> | Data Sources: Esri, DigitalGlobe, GeoEye, i-cubed, USDA FSA, USGS, AEX, Getmapping, Aerogrid, IGN, IGP, swisstopo';
	var satellite = L.tileLayer(satelliteUrl, {
		attribution: satelliteAttrib
	});
	let basemapControl = {
		'OSM': osm,
		'Satellite': satellite
	}
	L.control.layers(basemapControl).addTo(map);
	map.addControl(new L.Control.Fullscreen());
	map.addControl(new L.control.scale());
	map.addLayer(osm);
	const marker = L.marker([lastLatitude, lastLongitude]).addTo(map);
	marker.bindPopup(createPopupContent(lastLatitude, lastLongitude));
	
	g_last_lat = lastLatitude;
	g_last_lon = lastLongitude;
}

// Function to populate the flight details table
function populateFlightDetailsTable(lastLatitude, lastLongitude, lastAltitude) {
    const tableBody = document.getElementById('flight-details-body');
    const row = document.createElement('tr');
    row.innerHTML = `<td>${lastLatitude.slice(0, -1)}</td><td>${lastLongitude.slice(0, -1)}</td><td>${Math.trunc(lastAltitude)}</td>`;
    tableBody.appendChild(row);
}

// Function to fetch and populate the Data SQL table
async function fetchAndPopulateDataSqlTable(flightId) {
    try {
        const response = await fetch(`${api_address}flight_data.php?flight_id=${flightId}`);
        const flightData = await response.json();
		
		if (flightData.length === 0) {
            console.error('No flight data found for the given flight_id.');
			disableDownloadButton();
			disableDownloadKmlButton();
            return;
        }
		
		// Populate details table
        const tableBody = document.getElementById('data-sql-body');
        flightData.forEach(row => {
			let time = row.datetime.split(' ')[1];
            const rowData = `<td>${row.position_id}</td><td>${row.packet_no}</td>
							 <td>${time}</td><td>${row.vbat}</td>
							 <td>${row.latitude.slice(0, -1)}</td><td>${row.longitude.slice(0, -1)}</td>
							 <td>${Math.trunc(row.altitude)}</td>`;
            const newRow = document.createElement('tr');
            newRow.innerHTML = rowData;
            tableBody.appendChild(newRow);
        });
		
		// Draw trace on the map
		const trackCoordinates = [];
        flightData.forEach(row => {
            if (row.latitude !== null && row.longitude !== null 
					&& row.latitude != 0.0 && row.longitude != 0.0) {
                trackCoordinates.push([row.latitude, row.longitude]);
            }
        });
		
		var objectTrack = {
			"color": "#ff7800",
			"weight": 5,
			"opacity": 0.75
		};
		if (trackCoordinates.length > 0) {
			// Draw the trace on the map using Leaflet's L.polyline method
            L.polyline(trackCoordinates, objectTrack).addTo(map);
		}
		
		// Add the button to download all data from trackData
		enableDownloadButton(flightData);
		enableDownloadKmlButton(flightData);
		enableGoToGoogleMapsButton();
		
    } catch (error) {
        console.error('Error fetching Data SQL table:', error);
    }
}

// Function to download flight data as CSV file
function downloadFlightData(flightData) {
	var objectId = flightData[0].object_id;
	var flightId = flightData[0].flight_id;
    const fileName_raw = `data_tracker_${objectId}_flight_${flightId}.csv`;
	const fileName = sanitizeFileName(fileName_raw);

	var separator = ';'; // You can use ';' for a semicolon-separated CSV
	var keys = Object.keys(flightData[0]);
	var csvContent = keys.join(separator) + '\n';

	flightData.forEach((row) => {
		var values = keys.map((key) => row[key]);
		var rowString = values.join(separator);
		csvContent += rowString + '\n';
	});
	
	console.log(csvContent);

	const blob = new Blob([csvContent], { type: 'text/csv' });
	const url = URL.createObjectURL(blob);
	const a = document.createElement('a');
	a.href = url;
	a.download = fileName;
	a.click();
	URL.revokeObjectURL(url);
}

function downloadFlightDataKML(flightData) {
	var objectId = flightData[0].object_id;
	var flightId = flightData[0].flight_id;
    const fileName_raw = `data_tracker_${objectId}_flight_${flightId}.kml`;
	const fileName = sanitizeFileName(fileName_raw);

	var flight_coord = [];
	flightData.forEach((row) => {
			flight_coord.push({lat: row.latitude, lon: row.longitude, alt: row.altitude});

	});
	
	const kmlContent = generateTrackKML(`data_tracker_${objectId}_flight_${flightId}`, flight_coord);

	const blob = new Blob([kmlContent], { type: 'application/vnd.google-earth.kml+xml' });
	const url = URL.createObjectURL(blob);
	const a = document.createElement('a');
	a.href = url;
	a.download = fileName;
	a.click();
	URL.revokeObjectURL(url);
}

// Function to generate KML content for a track from the coordinates array
function generateTrackKML(name, coordinates) {
  let kml = `<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document>
    <name>${name}</name>
	<open>1</open>
	<Style id="failed">
		<LineStyle>
			<width>4</width>
		</LineStyle>
		<PolyStyle>
			<color>800000ff</color>
			<outline>0</outline>
		</PolyStyle>
	</Style>
	<Style id="failed0">
		<LineStyle>
			<width>4</width>
		</LineStyle>
		<PolyStyle>
			<color>800000ff</color>
			<outline>0</outline>
		</PolyStyle>
	</Style>
	<StyleMap id="failed1">
		<Pair>
			<key>normal</key>
			<styleUrl>#failed</styleUrl>
		</Pair>
		<Pair>
			<key>highlight</key>
			<styleUrl>#failed0</styleUrl>
		</Pair>
	</StyleMap>
    <Placemark>
      <name>Track</name>
	  <styleUrl>#failed1</styleUrl>
      <LineString>
		<extrude>1</extrude>
		<altitudeMode>absolute</altitudeMode>
        <coordinates>`;

  for (const coord of coordinates) {
    kml += `${coord.lon},${coord.lat},${coord.alt} `;
  }

  kml += `
      </coordinates>
      <altitudeMode>absolute</altitudeMode>
    </LineString>
  </Placemark>
</Document>
</kml>`;

  return kml;
}

function sanitizeFileName(fileName) {
  // Define a regular expression to match characters not allowed in file names
  const illegalChars = /[/\\?%*:|"<>]/g;

  // Replace illegal characters with an underscore
  const sanitizedFileName = fileName.replace(illegalChars, '_');

  return sanitizedFileName;
}

// Download CSV
// Function to rename the button and disable it
function disableDownloadButton() {
    const downloadBtn = document.getElementById('downloadLogFileBtn');
    downloadBtn.textContent = 'No Data Available';
    downloadBtn.disabled = true;
}

// Function to rename the button and enable it
function enableDownloadButton(data) {
    const downloadBtn = document.getElementById('downloadLogFileBtn');
    downloadBtn.textContent = 'Download details';
    downloadBtn.disabled = false;
	downloadBtn.addEventListener ('click', () => {
		downloadFlightData(data);
	});
}

// Download KML
// Function to rename the button and disable it
function disableDownloadKmlButton() {
    const downloadKmlBtn = document.getElementById('downloadKmlFileBtn');
    downloadKmlBtn.textContent = 'No Data Available';
    downloadKmlBtn.disabled = true;
}

// Function to rename the button and enable it
function enableDownloadKmlButton(data) {
    const downloadKmlBtn = document.getElementById('downloadKmlFileBtn');
    downloadKmlBtn.textContent = 'Download KML';
    downloadKmlBtn.disabled = false;
	downloadKmlBtn.addEventListener ('click', () => {
		downloadFlightDataKML(data);
	});
}

// Open Google Maps
function enableGoToGoogleMapsButton(data) {
    const mapsBtn = document.getElementById('openGoogleMapsBtn');
    mapsBtn.textContent = 'Open Google Maps';
    mapsBtn.disabled = false;
	mapsBtn.addEventListener ('click', () => {
		openGoogleMaps();
	});
}

// Function to download flight data as CSV file
function openGoogleMaps() {
	window.open('https://www.google.com/maps/search/?api=1&map_action=map?api=1&query=' + g_last_lat + ',' + g_last_lon, "_blank");
}

// Function to get the flight_id parameter from the URL
function getFlightIdFromURL() {
    const urlParams = new URLSearchParams(window.location.search);
    return urlParams.get('flight_id');
}

// Main function to load the page and fetch data
async function loadPage() {
    const flightId = getFlightIdFromURL();
    if (flightId) {
        const flightData = await fetchFlightData(flightId);
        if (flightData && flightData.length > 0) {
            const { last_latitude, last_longitude, last_altitude } = flightData[0];
            initMap(flightId, last_latitude, last_longitude);
            populateFlightDetailsTable(last_latitude, last_longitude, last_altitude);
            fetchAndPopulateDataSqlTable(flightId);
        } else {
            console.error('No flight data found.');
        }
    } else {
        console.error('No flight_id parameter in the URL.');
    }
}

// Call the main function to load the page
loadPage();

// Function to create the content for the popup
function createPopupContent(lastLatitude, lastLongitude) {
    const popupContent = document.createElement('div');

    // Create the Google map button in the popup
    const googleMapBtn = document.createElement('button');
    googleMapBtn.textContent = 'Google Map';
    googleMapBtn.addEventListener('click', () => {
        //window.location = 'https://www.google.com/maps/search/?api=1&map_action=map?api=1&query=' + lastLatitude + ',' + lastLongitude;
		window.open('https://www.google.com/maps/search/?api=1&map_action=map?api=1&query=' + lastLatitude + ',' + lastLongitude, "_blank");
    });
    popupContent.appendChild(googleMapBtn);

    return popupContent;
}

