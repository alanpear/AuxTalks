require("dotenv").config();

const DISCOGS_APi = "https://api.discogs.com";

async function searchAlbums(query) {
    const url = new URL(`${DISCOGS_APi}/database/search`);

    url.searchParams.append("q", query);
    url.searchParams.append("type", "release");
    url.searchParams.append("key", process.env.DISCOGS_CONSUMER_KEY);
    url.searchParams.append("secret", process.env.DISCOGS_CONSUMER_SECRET);

    const response = await fetch(url, {
        headers: {
            "User-Agent": "AuxTalks/1.0"
        }
    });
    
    if (!response.ok) {
        throw new Error(`Discogs API request failed: ${response.status}`);
    }

    return await response.json();
}

module.exports = {
    searchAlbums
};