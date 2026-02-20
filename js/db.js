// db.js

// Local database functions

const localDatabase = {
    data: {},

    addItem: function(key, value) {
        this.data[key] = value;
    },

    getItem: function(key) {
        return this.data[key];
    },

    removeItem: function(key) {
        delete this.data[key];
    },

    clear: function() {
        this.data = {};
    }
};

export default localDatabase;