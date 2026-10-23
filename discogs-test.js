const { searchAlbums } = require("./discogs");

searchAlbums("Nirvana")
    .then(data => {
        console.log("Search results:", data);
    })
    .catch(error => {
        console.error("Error searching albums:", error);
    });