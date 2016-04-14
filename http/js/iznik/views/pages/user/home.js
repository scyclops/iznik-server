define([
    'jquery',
    'underscore',
    'backbone',
    'iznik/base',
    'iznik/views/pages/pages'
], function($, _, Backbone, Iznik) {
    Iznik.Views.User.Pages.Home = Iznik.Views.Page.extend({
        template: "user_home_main",

        render: function() {
            var self = this;

            Iznik.Views.Page.prototype.render.call(this, {
                noSupporters: true
            });

            // It's quicker to get all our messages in a single call.  So we have two CollectionViews, one for offers,
            // one for wanteds.
            self.offers = new Iznik.Collection();
            self.wanteds = new Iznik.Collection();

            self.offersView = new Backbone.CollectionView({
                el: self.$('.js-offers'),
                modelView: Iznik.Views.User.Home.Offer,
                modelViewOptions: {
                    collection: self.offers,
                    page: self
                },
                collection: self.offers
            });

            self.offersView.render();

            self.wantedsView = new Backbone.CollectionView({
                el: self.$('.js-wanteds'),
                modelView: Iznik.Views.User.Home.Wanted,
                modelViewOptions: {
                    collection: self.wanteds,
                    page: self
                },
                collection: self.wanteds
            });

            self.wantedsView.render();

            // And a collection for all the messages.
            self.messages = new Iznik.Collections.Message(null, {
                collection: 'Approved'
            });

            // We listen for events on the messages collection and ripple them through to the relevant offers/wanteds
            // collection.  CollectionView will then handle rendering/removing the messages view.
            self.listenTo(self.messages, 'add', function(msg) {
                var related = msg.get('related');

                if (msg.get('type') == 'Offer') {
                    var taken = _.where(related, {
                        type: 'Taken'
                    });

                    if (taken.length == 0) {
                        self.offers.add(msg);
                    }
                } else if (msg.get('type') == 'Wanted') {
                    var received = _.where(related, {
                        type: 'Received'
                    });

                    if (received.length == 0) {
                        self.wanteds.add(msg);
                    }
                }
            });

            self.listenTo(self.messages, 'remove', function(msg) {
                console.log("Removed", msg);

                if (this.model.get('type') == 'Offer') {
                    self.offers.remove(msg);
                } else if (this.model.get('type') == 'Wanted') {
                    self.wanteds.remove(msg);
                }
            });

            // Now get the messages.
            self.messages.fetch({
                data: {
                    fromuser: Iznik.Session.get('me').id,
                    types: ['Offer', 'Wanted']
                }
            }).then(function() {
                if (self.offers.length == 0) {
                    self.$('.js-nooffers').fadeIn('slow');
                } else {
                    self.$('.js-nooffers').hide();
                }
            });

            return(this);
        }
    });

    Iznik.Views.User.Home.Message = Iznik.View.extend({
        render: function() {
            var self = this;

            Iznik.View.prototype.render.call(self);
            var groups = self.model.get('groups');

            _.each(groups, function(group) {
                var v = new Iznik.Views.User.Home.Group({
                    model: new Iznik.Model(group)
                });
                self.$('.js-groups').append(v.render().el);
            });

            _.each(self.model.get('attachments'), function (att) {
                var v = new Iznik.Views.User.Home.Photo({
                    model: new Iznik.Model(att)
                });

                self.$('.js-attlist').append(v.render().el);
            });

            return(this);
        }
    });

    Iznik.Views.User.Home.Offer = Iznik.Views.User.Home.Message.extend({
        template: "user_home_offer",

        className: "panel panel-default"
    });

    Iznik.Views.User.Home.Wanted = Iznik.Views.User.Home.Message.extend({
        template: "user_home_wanted",

        className: "panel panel-default"
    });

    Iznik.Views.User.Home.Group = Iznik.View.extend({
        template: "user_home_group",

        render: function() {
            Iznik.View.prototype.render.call(this);
            this.$('.timeago').timeago();
            return(this);
        }
    });

    Iznik.Views.User.Home.Photo = Iznik.View.extend({
        tagName: 'li',

        template: 'user_home_photo'
    });
});