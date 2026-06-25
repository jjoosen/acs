$(function(){
    // Check gmaps key available on page
    if(document.getElementById('map') != null
        && document.getElementById('map').dataset.gmapsKey
        && typeof locations !== 'undefined'
    ) {
        // Import gmaps api
        import('load-google-maps-api').then(({default: loadGoogleMapsApi}) => {
            // Only initiate map once gmaps is loaded
            loadGoogleMapsApi({
                key: document.getElementById('map').dataset.gmapsKey,
                libraries: [
                    'places'
                ],
                region: 'BE'
            }).then(function (googleMaps) {
                const map = new google.maps.Map(document.getElementById('map'), {
                    maxZoom: 17,
                    styles: [
                        {
                            "featureType": "landscape.natural",
                            "elementType": "geometry.fill",
                            "stylers": [
                                {
                                    "visibility": "on"
                                },
                                {
                                    "color": "#e0efef"
                                }
                            ]
                        },
                        {
                            "featureType": "poi",
                            "elementType": "geometry.fill",
                            "stylers": [
                                {
                                    "visibility": "on"
                                },
                                {
                                    "hue": "#1900ff"
                                },
                                {
                                    "color": "#c0e8e8"
                                }
                            ]
                        },
                        {
                            "featureType": "road",
                            "elementType": "geometry",
                            "stylers": [
                                {
                                    "lightness": 100
                                },
                                {
                                    "visibility": "simplified"
                                }
                            ]
                        },
                        {
                            "featureType": "road",
                            "elementType": "labels",
                            "stylers": [
                                {
                                    "visibility": "off"
                                }
                            ]
                        },
                        {
                            "featureType": "transit.line",
                            "elementType": "geometry",
                            "stylers": [
                                {
                                    "visibility": "on"
                                },
                                {
                                    "lightness": 700
                                }
                            ]
                        },
                        {
                            "featureType": "water",
                            "elementType": "all",
                            "stylers": [
                                {
                                    "color": "#7dcdcd"
                                }
                            ]
                        }
                    ]
                });

                const icon = {
                    url: '/build/images/marker.png',
                    scaledSize: new google.maps.Size(23, 35)
                };

                const iconHover = {
                    url: '/build/images/marker-hover.png',
                    scaledSize: new google.maps.Size(23, 35)
                };

                const bounds = new google.maps.LatLngBounds();

                locations.forEach(function(location, i) {
                    if (location.lat && location.lng) {
                        const latLng = new google.maps.LatLng(location.lat, location.lng);

                        // Store lat and lng
                        locations[i].lat = latLng.lat();
                        locations[i].lng = latLng.lng();

                        var contentString =
                            '<div id="content" style="color: #1b1b1c">'+
                            '<h5 id="firstHeading" class="firstHeading" style="margin-bottom:5px">' + location.title +'</h5>'+
                            '<div id="bodyContent">'+ location.street + ', ' + location.city + '<br><br>';

                        if(location.url) {
                            contentString += '<a href="' + location.url + '" style="text-decoration: underline; color: #0099dd">Meer info</a>';
                        }

                        contentString += '</div>' +
                            '</div>';

                        var infowindow = new google.maps.InfoWindow({
                            content: contentString
                        });

                        const marker = new google.maps.Marker({
                            position: latLng,
                            map: map,
                            icon: icon,
                            iconOriginal: icon,
                            iconHover: iconHover
                        });

                        // Automatic zoom and center
                        bounds.extend(latLng);

                        if (locations.length === 1) {
                            map.setCenter(latLng);
                            map.setZoom(13);
                        } else {
                            map.fitBounds(bounds);
                        }

                        google.maps.event.addListener(marker, 'mouseover', function () {
                            this.setIcon(this.iconHover);
                        });

                        google.maps.event.addListener(marker, 'mouseout', function () {
                            this.setIcon(this.iconOriginal);
                        });

                        google.maps.event.addListener(marker, 'click', function () {
                            infowindow.open(map, marker);
                        });
                    } else {
                        console.warn('Could not find location for address: ' + location.street + ' ' + location.city);
                    }
                });

                /**
                 * SEARCH
                 */
                var input = document.getElementById("search");

                if(input !== null) {
                    var autocomplete = new google.maps.places.Autocomplete(input);
                    autocomplete.bindTo('bounds', map);
                    autocomplete.addListener('place_changed', function () {
                        input.closest('form').dispatchEvent(new Event('submit'));
                        var place = autocomplete.getPlace();
                        if (!place.geometry) {
                            return;
                        }

                        // If the place has a geometry, then present it on a map.
                        if (place.geometry.viewport) {
                            map.fitBounds(place.geometry.viewport);
                        } else {
                            map.setCenter(place.geometry.location);
                            map.setZoom(8);
                        }
                    });
                }
            })
        });
    }
});