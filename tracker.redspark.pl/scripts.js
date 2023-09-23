// Function to fetch data and populate the table and update markers on the map
function fetchDataAndAddMarkers() {
	try{
		fetch('https://tracker.redspark.pl/flights.php')
			.then(response => response.json())
			.then(data => {
				const tableBody = document.querySelector('#data-table tbody');

				// Clear the previous table data and markers on the map
				tableBody.innerHTML = '';
				map.eachLayer(function (layer) {
					if (!(layer instanceof L.TileLayer)) {
						map.removeLayer(layer);
					}
				});

				data.forEach(flight => {
					var tracksLayer = L.layerGroup();
					var markersLayer = L.layerGroup();
					
					// Calculate the time difference between now and the "End Time" in seconds
					const timeDifference = calculateTimeDifference(flight.end_time);
					
					// Check if latitude and longitude are not null
					if (flight.last_latitude !== null && flight.last_longitude !== null) {
						// Add marker to the map at the given latitude and longitude
						const marker = L.marker([flight.last_latitude, flight.last_longitude]);
						// Add a popup with flight details to the marker
						marker.bindPopup(createPopupContent(flight.object_id, flight.flight_id, flight.last_latitude, flight.last_longitude, flight.last_altitude, timeDifference));
						markersLayer.addLayer(marker);
					}

					// Fetch track data for the flight
					try{
						var flight_id = flight.flight_id;
						fetch('https://tracker.redspark.pl/flight_sum.php?flight_id=' + flight_id)
						.then(response => response.json())
						.then(trackData => {
							// Create an array to store track coordinates
							const trackCoordinates = [];
							
							trackData.forEach(track => {
								const { latitude, longitude } = track;
								if (latitude !== null && longitude !== null) {
									trackCoordinates.push([latitude, longitude]);
								}
							});
							
							var objectTrack = {
								"color": "#ff7800",
								"weight": 5,
								"opacity": 0.75
							};
						
							// Add the track to the map if it has valid coordinates
							if (trackCoordinates.length > 0) {
								const track = L.polyline(trackCoordinates, objectTrack);
								track.bindPopup(createPopupContent(flight.object_id, flight.flight_id, flight.latitude, flight.longitude, flight.altitude, timeDifference));
								//tracksLayer.addLayer(track);
							}
						});
					}
					catch(error){
						console.error('Error fetching data from flight_sum.php:', error);
					}
					
						
					
					// Add the marker and track layers to the map
					map.addLayer(markersLayer);
					map.addLayer(tracksLayer);

					// Populate the table
					const row = document.createElement('tr');
					row.innerHTML = `
						<td>${flight.flight_id}</td>
						<td>${flight.object_id}</td>
						<td>${timeDifference}</td>
						<td>${flight.last_latitude}</td>
						<td>${flight.last_longitude}</td>
						<td>${flight.last_altitude}</td>
						<td>
							<button class="details-button" onclick="openFlightDetails(${flight.flight_id}, ${flight.object_id})">Details</button>
						</td>
					`;
					tableBody.appendChild(row);

					// Add a click event listener to the row
						if (window.innerWidth <= 768) {
								row.addEventListener('click', () => {
									openFlightDetails(flight.flight_id, flight.object_id);
								});
						}
				});
			});
	}
	catch(error){
		console.error('Error fetching data flights.php:', error);
	}
}

function openFlightDetails(flightId, objectId){
		const subpageUrl = `flight_details.html?flight_id=${flightId}`;
		window.location.href = subpageUrl;
}

// Function to download flight data as CSV file
function downloadFlightData(flightId, objectId) {
    const fileName = `data_tracker_${objectId}_flight_${flightId}.csv`;
    const url = `https://tracker.redspark.pl/flight_data.php?flight_id=${flightId}`;

    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
		alert('Error downloading flight data. Please try again later.')

            }
            return response.text();
        })
        .then(textData => {
			var jsonData = JSON.parse(textData);
			
			var separator = ';'; // You can use ';' for a semicolon-separated CSV
			var keys = Object.keys(jsonData[0]);
			var csvContent = keys.join(separator) + '\n';

			jsonData.forEach((row) => {
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
        })
        .catch(error => {
	    console.error('Error downloading flight data:', error);
	    alert('Error downloading flight data. Please try again later.');
	});
}


// Create the map with Leaflet
var map;
function mapInit() {
	map = L.map('map-container').setView([50.337335, 19.521827], 15);

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
}



// Function to calculate the time difference between now and the "Start Time" in seconds, minutes, or hours
function calculateTimeDifference(startTime) {
    const currentTime = new Date();
    const startTimeDate = new Date(startTime);
    const timeDifferenceInSeconds = Math.floor((currentTime - startTimeDate) / 1000);

    if (timeDifferenceInSeconds < 60) {
        return timeDifferenceInSeconds + 's';
    } else if (timeDifferenceInSeconds < 3600) {
        const minutes = Math.floor(timeDifferenceInSeconds / 60);
        return minutes + 'min';
    } else {
        const hours = Math.floor(timeDifferenceInSeconds / 3600);
        return hours + 'h';
    }
}

// Function to create the content for the popup
function createPopupContent(objectId, flightId, lastLatitude, lastLongitude, lastAltitude, timeDifference) {
    const popupContent = document.createElement('div');

    const infoElement = document.createElement('p');
infoElement.innerHTML = `Object ID: ${objectId}<br>Last Latitude: ${lastLatitude}<br>Last Longitude: ${lastLongitude}<br>Last Altitude: ${lastAltitude}<br>Data Age: ${timeDifference}`;
    popupContent.appendChild(infoElement);
 
    // Create the download button in the popup
    const downloadBtn = document.createElement('button');
    downloadBtn.textContent = 'Details';
    downloadBtn.addEventListener('click', () => {
        openFlightDetails(flightId, objectId);
    });
    popupContent.appendChild(downloadBtn);

    return popupContent;
}

function loadPage(){
	mapInit();
	
	// Call the function to fetch data, populate the table, and update markers on page load
	fetchDataAndAddMarkers();

	// Refresh data and markers every 15 seconds
	setInterval(fetchDataAndAddMarkers, 15000);
}