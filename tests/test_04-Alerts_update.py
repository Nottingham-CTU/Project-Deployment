# Generated from Selenium IDE
# Test name: t04 - Alerts update
import pytest
import time
import json
from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support import expected_conditions
from selenium.webdriver.support.wait import WebDriverWait

class Test_04_Alerts_update:
  def setup_method(self, method):
    self.driver = self.selectedBrowser
    self.vars = {}
  def teardown_method(self, method):
    self.driver.quit()

  def test_04_Alerts_update(self):
    self.driver.get("http://127.0.0.1/")
    self.driver.find_element(By.LINK_TEXT, "My Projects").click()
    assert len(self.driver.find_elements(By.XPATH, "//*[@id=\"table-proj_table\"][contains(.,'Project Deployment Test 1')]")) > 0
    assert len(self.driver.find_elements(By.XPATH, "//*[@id=\"table-proj_table\"][contains(.,'Project Deployment Test 2')]")) > 0
    self.driver.find_element(By.LINK_TEXT, "Project Deployment Test 1").click()
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"route=AlertsController:setup\"]").click()
    self.driver.find_element(By.ID, "addNewAlert").click()
    None if (element := self.driver.find_element(By.ID, "alert-trigger2")).is_selected() else element.click()
    self.driver.execute_script("$('#alert-trigger2').trigger('click')")
    self.driver.find_element(By.NAME, "form-name").find_element(By.CSS_SELECTOR, "*[value='demographics-']").click()
    self.driver.execute_script("$('#alert-condition').val('1=2')")
    self.driver.execute_script("$('[name=\"email-to-freeform\"]').val('test@example.com')")
    self.driver.execute_script("$('[name=\"email-subject\"]').val('test')")
    self.driver.execute_script("tinymce.activeEditor.setContent('Test')")
    self.driver.execute_script("$('#south').remove()")
    self.driver.find_element(By.ID, "btnModalsaveAlert").click()
    WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.ID, "south")))
    self.driver.find_element(By.LINK_TEXT, "My Projects").click()
    self.driver.find_element(By.LINK_TEXT, "Project Deployment Test 2").click()
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"ExternalModules/\"][href*=\"prefix=project_deployment\"]").click()
    self.driver.execute_script("if(window.location.hostname=='127.0.0.1'&&$('input[name=\"username\"]').length>0){$('input[name=\"username\"]').val('admin');$('input[name=\"password\"]').val('abc123');$('form[method=\"post\"] input[type=\"submit\"]').trigger('click')}")
    None if len(elements := self.driver.find_elements(By.CSS_SELECTOR, "input[name=\"username\"]")) == 0 else WebDriverWait(self.driver, 30).until(expected_conditions.staleness_of(elements[0]))
    self.vars["count"] = self.driver.execute_script("return $('.changestbl tr').length")
    assert(self.vars["count"] == "2")
    assert len(self.driver.find_elements(By.CSS_SELECTOR, "input[name=\"update[alerts]\"]")) > 0
    None if (element := self.driver.find_element(By.CSS_SELECTOR, "input[name=\"update[alerts]\"]")).is_selected() else element.click()
    self.driver.find_element(By.CSS_SELECTOR, "input[type=\"submit\"]").click()
    self.vars["count"] = self.driver.execute_script("return $('.changestbl tr').length")
    assert(self.vars["count"] == "0")
