/**
 * Pollora — the editor side of Block Bindings.
 *
 * The server registers every Pollora source (#[BlockBinding] classes and the
 * pollora/* meta sources) and the editor learns their names and labels. This
 * script adds what the editor needs to use them:
 *
 *  - getFieldsList: the fields of the source, so the block's "Attributes"
 *    panel offers them; a meta source offers the meta of the post type (or
 *    taxonomy) being edited;
 *  - getValues: the value each bound block shows, computed on the server by
 *    the same code as the page and asked in one request for all the blocks
 *    waiting for a value (POST pollora/v1/block-bindings/resolve).
 *
 * The values are read-only: the block shows them, the editor does not write
 * them back. While a value loads, and when the source gives none, the block
 * shows the field's label.
 *
 * Plain JavaScript on the wp.* globals, shipped without a build as the inline
 * script of the "pollora-block-bindings" handle, after window.polloraBlockBindings.
 */
(function (wp, root) {
    'use strict';

    var config = root.polloraBlockBindings;

    if (!config || !wp.blocks || !wp.blocks.registerBlockBindingsSource) {
        return;
    }

    var STORE = 'pollora/block-bindings';

    /** Wait for the other blocks of the same render before asking the server. */
    var BATCH_DELAY = 20;

    var pending = {};
    var timer = null;

    /** The key of one bound attribute, for the store and the request. The block decides its escaping. */
    function keyOf(sourceName, args, context, blockName, attribute) {
        return JSON.stringify([sourceName, args || {}, context.postId || null, context.postType || null, context.termId || null, context.taxonomy || null, blockName, attribute]);
    }

    function flush(dispatch) {
        var batch = pending;
        var byContext = {};

        pending = {};
        timer = null;

        Object.keys(batch).forEach(function (key) {
            var item = batch[key];
            var contextKey = JSON.stringify(item.context);

            byContext[contextKey] = byContext[contextKey] || { context: item.context, bindings: [] };
            byContext[contextKey].bindings.push({ key: key, source: item.source, args: item.args, block: item.block, attribute: item.attribute });
        });

        Object.keys(byContext).forEach(function (contextKey) {
            var request = byContext[contextKey];
            var keys = request.bindings.map(function (binding) { return binding.key; });

            wp.apiFetch({ path: '/' + config.route, method: 'POST', data: request })
                .then(function (response) {
                    var values = {};

                    keys.forEach(function (key) {
                        values[key] = response && response.values && key in response.values ? response.values[key] : null;
                    });
                    dispatch.receiveValues(values);
                })
                .catch(function () {
                    var values = {};

                    keys.forEach(function (key) { values[key] = null; });
                    dispatch.receiveValues(values);
                });
        });
    }

    var store = wp.data.createReduxStore(STORE, {
        reducer: function (state, action) {
            state = state || {};

            if (action.type === 'RECEIVE_VALUES') {
                return Object.assign({}, state, action.values);
            }

            return state;
        },
        actions: {
            receiveValues: function (values) {
                return { type: 'RECEIVE_VALUES', values: values };
            },
        },
        selectors: {
            /** The value of a bound attribute; undefined while it loads. */
            getValue: function (state, key) {
                return state[key];
            },
        },
        resolvers: {
            getValue: function (key) {
                return function (thunk) {
                    var item = JSON.parse(key);

                    pending[key] = {
                        source: item[0],
                        args: item[1],
                        context: pick({ postId: item[2], postType: item[3], termId: item[4], taxonomy: item[5] }),
                        block: item[6],
                        attribute: item[7],
                    };

                    if (!timer) {
                        timer = setTimeout(function () { flush(thunk.dispatch); }, BATCH_DELAY);
                    }
                };
            },
        },
    });

    wp.data.register(store);

    /** The members that have a value. */
    function pick(object) {
        var result = {};

        Object.keys(object).forEach(function (name) {
            if (object[name] !== null && object[name] !== undefined) {
                result[name] = object[name];
            }
        });

        return result;
    }

    function sameArgs(a, b) {
        return JSON.stringify(a || {}) === JSON.stringify(b || {});
    }

    /** The fields a source offers in this context. */
    function fieldsFor(source, context) {
        if (source.kind === 'meta') {
            var subtype = source.subtype ? context[source.subtype] : null;

            return (source.fields[''] || []).concat(subtype ? source.fields[subtype] || [] : []);
        }

        if (source.postTypes.length && (!context.postType || source.postTypes.indexOf(context.postType) === -1)) {
            return [];
        }

        return source.fields;
    }

    function labelOf(source, args, context) {
        var field = fieldsFor(source, context).filter(function (item) { return sameArgs(item.args, args); })[0];

        return field ? field.label : null;
    }

    Object.keys(config.sources).forEach(function (name) {
        var source = config.sources[name];

        wp.blocks.registerBlockBindingsSource({
            name: name,

            getFieldsList: function (options) {
                return fieldsFor(source, options.context || {});
            },

            getValues: function (options) {
                var context = options.context || {};
                var values = {};
                var blockEditorStore = options.select(wp.blockEditor.store);
                var attributes = options.clientId ? blockEditorStore.getBlockAttributes(options.clientId) || {} : {};
                var blockName = options.clientId ? blockEditorStore.getBlockName(options.clientId) : null;

                Object.keys(options.bindings).forEach(function (attribute) {
                    var args = options.bindings[attribute].args;
                    var value = options.select(STORE).getValue(keyOf(name, args, context, blockName, attribute));

                    if (value === undefined || value === null || value === '') {
                        // Loading, or no value: what the block holds, else the field's label
                        value = attributes[attribute] || labelOf(source, args, context) || '';
                    }

                    values[attribute] = typeof value === 'number' || typeof value === 'string' ? value : String(value);
                });

                return values;
            },
        });
    });
})(window.wp, window);
