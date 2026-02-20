// API request functions

/**
 * Function to make a GET request.
 * @param {string} url - The URL to make the request to.
 * @returns {Promise} - A promise resolving to the response.
 */
const getRequest = async (url) => {
    const response = await fetch(url);
    if (!response.ok) {
        throw new Error('Network response was not ok ' + response.statusText);
    }
    return await response.json();
};

/**
 * Function to make a POST request.
 * @param {string} url - The URL to make the request to.
 * @param {Object} data - The data to send with the request.
 * @returns {Promise} - A promise resolving to the response.
 */
const postRequest = async (url, data) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(data)
    });
    if (!response.ok) {
        throw new Error('Network response was not ok ' + response.statusText);
    }
    return await response.json();
};

/**
 * Function to make a PUT request.
 * @param {string} url - The URL to make the request to.
 * @param {Object} data - The data to send with the request.
 * @returns {Promise} - A promise resolving to the response.
 */
const putRequest = async (url, data) => {
    const response = await fetch(url, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(data)
    });
    if (!response.ok) {
        throw new Error('Network response was not ok ' + response.statusText);
    }
    return await response.json();
};

/**
 * Function to make a DELETE request.
 * @param {string} url - The URL to make the request to.
 * @returns {Promise} - A promise resolving to the response.
 */
const deleteRequest = async (url) => {
    const response = await fetch(url, {
        method: 'DELETE'
    });
    if (!response.ok) {
        throw new Error('Network response was not ok ' + response.statusText);
    }
    return await response.json();
};

export { getRequest, postRequest, putRequest, deleteRequest };