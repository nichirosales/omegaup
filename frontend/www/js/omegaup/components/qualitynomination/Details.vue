<template>
  <div class="panel panel-default">
    <div class="panel-heading">
      <h2 class="panel-title">{{ T.wordsReviewingProblem }}</h2>
    </div>
    <div class="panel-body">
      <div class="container-fluid">
        <div class="row">
          <div class="col-sm-3">
            <strong>{{ T.qualityNominationType }}</strong>
          </div>
          <div class="col-sm-4">
            {{ this.nomination }}
          </div>
        </div>
        <div class="row">
          <div class="col-sm-3">
            <strong>{{ T.qualityUserThatNominated }}</strong>
          </div>
          <div class="col-sm-4">
            {{ this.nominator.name }} (<a v-bind:href="userUrl(this.nominator.username)">{{
            this.nominator.username }}</a>)
          </div>
        </div>
        <div class="row">
          <div class="col-sm-3">
            <strong>{{ T.wordsProblem }}</strong>
          </div>
          <div class="col-sm-4">
            {{ this.problem.title }} (<a v-bind:href="problemUrl(this.problem.alias)">{{
            this.problem.alias }}</a>)
          </div>
        </div>
        <div class="row">
          <div class="col-sm-3">
            <strong>{{ T.wordsDetails }}</strong>
          </div>
          <div class="col-sm-4">
            {{ this.contents }}
          </div>
        </div>
        <div class="row"
             v-if="this.nomination == 'demotion' &amp;&amp; this.reviewer == true">
          <div class="col-sm-3">
            <strong>{{ T.wordsVerdict }}</strong>
          </div>
          <div class="col-sm-4">
            <button class="btn btn-danger"
                 v-on:click="markResolution(true)">{{ T.wordsBanProblem }}</button> <button class=
                 "btn btn-success"
                 v-on:click="markResolution(false)">{{ T.wordsKeepProblem }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import {T, API} from '../../omegaup.js';
import UI from '../../ui.js';

export default {
  props: {
    contents: Object,
    nomination: String,
    nominator: {username: String, name: String},
    problem: {alias: String, title: String},
    qualitynomination_id: Number,
    reviewer: Boolean,
    votes: Array
  },
  data: function() {
    return {
      T: T,
    };
  },
  methods: {
    userUrl: function(alias) { return '/profile/' + alias + '/';},
    problemUrl: function(alias) { return '/arena/problem/' + alias + '/';},
    markResolution: function(banProblem) {
      let newStatus = banProblem ? 'approved' : 'denied';
      API.QualityNomination.resolve({
                             problem: this.problem.alias,
                             status: newStatus,
                             qualitynomination_id: this.qualitynomination_id
                           })
          .fail(UI.apiError);
    },
  }
};
</script>
